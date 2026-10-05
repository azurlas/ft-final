<?php

declare(strict_types=1);

namespace App\Payment;

use App\Payment\Message\ProcessPurchase;
use App\RuntimeProfile;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PurchaseHandler
{
    public function __construct(private Connection $db, private HttpPaymentService $provider, private MockScenarios $scenarios, private CircuitBreaker $circuit, private RuntimeProfile $profile, private LoggerInterface $logger, private ?FailureHooks $hooks = null)
    {
    }
    public function __invoke(ProcessPurchase $msg): void
    {
        $p = $this->db->fetchAssociative('SELECT * FROM purchases WHERE id=?', [$msg->purchaseId]);
        if (!$p || in_array($p['status'], ['succeeded','failed','declined'])) {
            return;
        }
        if (!$this->circuit->allow()) {
            $this->logger->notice('payment.circuit_deferred', ['purchase_id' => $msg->purchaseId]);
            return;
        }
        $attempt = $this->db->transactional(function () use ($p) {
            $this->db->fetchOne('SELECT id FROM users WHERE id=? FOR UPDATE', [$p['user_id']]);
            $this->db->fetchOne('SELECT id FROM courses WHERE id=? FOR UPDATE', [$p['course_id']]);
            $r = $this->db->fetchAssociative('SELECT * FROM purchases WHERE id=? FOR UPDATE', [$p['id']]);
            if (in_array($r['status'], ['succeeded','failed','declined']) || ($r['processing_lease_until'] && strtotime($r['processing_lease_until'].' UTC') > time())) {
                return null;
            }
            $last = $this->db->fetchAssociative('SELECT * FROM payment_attempts WHERE purchase_id=? ORDER BY attempt_number DESC LIMIT 1', [$r['id']]);
            if ($last && $last['next_attempt_at'] && strtotime($last['next_attempt_at'].' UTC') > time()) {
                return null;
            }
            $n = $last && $last['status'] === 'started' ? (int)$last['attempt_number'] : ($last ? (int)$last['attempt_number'] + 1 : 1);
            if ($n > $this->profile->maxAttempts()) {
                return null;
            }
            if (!$last || $last['status'] !== 'started') {
                $this->db->executeStatement("INSERT INTO payment_attempts (purchase_id,attempt_number,status,started_at,duration_ms,created_at) VALUES (?,?,'started',UTC_TIMESTAMP(),0,UTC_TIMESTAMP())", [$r['id'],$n]);
            }
            $this->db->executeStatement("UPDATE purchases SET status='processing',processing_lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 SECOND),updated_at=UTC_TIMESTAMP() WHERE id=?", [$r['id']]);
            return $n;
        });
        if ($attempt === null) {
            return;
        }
        $meta = json_decode($p['metadata'], true, 512, JSON_THROW_ON_ERROR)[0];
        $start = microtime(true);
        $result = $this->provider->pay($this->scenarios->dto($meta['fixture'], $meta['beneficiaryName']), new PaymentContext((int)$p['id'], $attempt, (int)$p['amount'], $p['currency']));
        $duration = (int)((microtime(true) - $start) * 1000);
        if ($result->status === 'succeeded') {
            $this->hooks?->checkpoint('after_provider_success', (int)$p['id']);
        }
        $settled = $this->db->transactional(function () use ($p, $attempt, $result, $duration) {
            $this->db->fetchOne('SELECT id FROM users WHERE id=? FOR UPDATE', [$p['user_id']]);
            $this->db->fetchOne('SELECT id FROM courses WHERE id=? FOR UPDATE', [$p['course_id']]);
            $locked = $this->db->fetchAssociative('SELECT * FROM purchases WHERE id=? FOR UPDATE', [$p['id']]);
            if (in_array($locked['status'], ['succeeded','failed','declined'])) {
                return false;
            }
            if ($this->db->fetchOne('SELECT status FROM payment_attempts WHERE purchase_id=? AND attempt_number=? FOR UPDATE', [$p['id'],$attempt]) !== 'started') {
                return false;
            }
            $retry = $result->status === 'transient' && $attempt < $this->profile->maxAttempts();
            $status = $retry ? 'retrying' : ($result->status === 'transient' ? 'failed' : $result->status);
            $delay = 2 ** $attempt;
            $this->db->executeStatement('UPDATE payment_attempts SET status=?,provider_reference=?,error_code=?,duration_ms=?,finished_at=UTC_TIMESTAMP(),next_attempt_at='.($retry ? 'DATE_ADD(UTC_TIMESTAMP(),INTERVAL '.$delay.' SECOND)' : 'NULL').' WHERE purchase_id=? AND attempt_number=?', [$result->status,$result->reference,$result->error,$duration,$p['id'],$attempt]);
            $this->db->executeStatement('UPDATE purchases SET status=?,processing_lease_until=NULL,updated_at=UTC_TIMESTAMP(),completed_at='.($retry ? 'NULL' : 'UTC_TIMESTAMP()').' WHERE id=?', [$status,$p['id']]);
            if ($result->status === 'succeeded') {
                $this->db->executeStatement("INSERT INTO enrollments (user_id,course_id,purchase_id,source,created_at) VALUES (?,?,?,'paid',UTC_TIMESTAMP())", [$p['user_id'],$p['course_id'],$p['id']]);
            }
            if ($retry) {
                CheckoutService::outbox($this->db, (int)$p['id'], $delay);
            }
            return true;
        });
        if (!$settled) {
            return;
        }
        $this->circuit->record($result->status === 'transient');
        $this->logger->info('payment.attempt_completed',['request_id' => $msg->requestId,'purchase_id' => $p['id'],'attempt' => $attempt,'status' => $result->status,'duration_ms' => $duration]);
    }
}
