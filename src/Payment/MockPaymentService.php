<?php

declare(strict_types=1);

namespace App\Payment;

use App\Payment\Dto\PaymentDetailsDto;
use Doctrine\DBAL\Connection;

/** Hard-coded provider scenarios, with durable success receipts before replies. */
final class MockPaymentService implements PaymentServiceInterface
{
    public const CARDS = ['success' => '9900000000000010','retry_once' => '9900000000000028','retry_twice' => '9900000000000036','failure' => '9900000000000044','decline' => '9900000000000051','timeout' => '9900000000000069'];
    public static function outcome(string $fixture, int $attempt): string
    {
        return match($fixture) {
            'success' => 'succeeded','retry_once' => $attempt >= 2 ? 'succeeded' : 'transient','retry_twice' => $attempt >= 3 ? 'succeeded' : 'transient','failure' => 'transient','decline' => 'declined','timeout' => 'timeout',default => throw new \LogicException('Unknown fixture')
        };
    }
    public function __construct(private Connection $db, private MockScenarios $scenarios)
    {
    }
    public function pay(PaymentDetailsDto $details, PaymentContext $context): PaymentResult
    {
        $fixture = $this->scenarios->resolve($details);
        $ref = $this->db->fetchOne('SELECT provider_reference FROM mock_receipts WHERE purchase_id=?', [$context->purchaseId]);
        if ($ref) {
            return new PaymentResult('succeeded', $ref);
        }
        $outcome = self::outcome($fixture, $context->attemptNumber);
        if ($outcome === 'timeout') {
            usleep(3000000);
            return new PaymentResult('transient', null, 'provider_timeout');
        }
        if ($outcome !== 'succeeded') {
            return new PaymentResult($outcome, null, $outcome === 'declined' ? 'card_declined' : 'provider_unavailable');
        }
        $ref = 'mock-'.hash('sha256', 'purchase:'.$context->purchaseId);
        $this->db->executeStatement('INSERT IGNORE INTO mock_receipts (purchase_id,provider_reference,amount,currency,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$context->purchaseId,$ref,$context->amount,$context->currency]);
        return new PaymentResult('succeeded',$ref);
    }
}
