<?php

declare(strict_types=1);

namespace App\Payment;

use App\Api\State\{Access,Mapper};
use App\Payment\Dto\PaymentDetailsDto;
use App\Payment\Message\ProcessPurchase;
use App\RuntimeProfile;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,ConflictHttpException,NotFoundHttpException};

final class CheckoutService
{
    public function __construct(private Connection $db, private Access $access, private Mapper $mapper, private MockScenarios $scenarios, private RuntimeProfile $profile, private MessageBusInterface $bus, private \Symfony\Component\HttpFoundation\RequestStack $requests)
    {
    }
    public function enroll(int $courseId): object
    {
        $u = $this->access->user();
        $id = $this->db->transactional(function () use ($u, $courseId) {
            $this->db->fetchOne('SELECT id FROM users WHERE id=? FOR UPDATE', [$u->id]);
            $c = $this->db->fetchAssociative('SELECT * FROM courses WHERE id=? FOR UPDATE', [$courseId]);
            if (!$c || $c['deleted_at']) {
                throw new NotFoundHttpException();
            }
            if ($c['price'] !== null && (int)$c['price'] > 0) {
                throw new ConflictHttpException('Paid course requires purchase');
            }
            if ($this->db->fetchOne("SELECT id FROM purchases WHERE user_id=? AND course_id=? AND status IN ('pending','processing','retrying')", [$u->id,$courseId])) {
                throw new ConflictHttpException('Purchase is active');
            }
            $id = $this->db->fetchOne('SELECT id FROM enrollments WHERE user_id=? AND course_id=?', [$u->id,$courseId]);
            if (!$id) {
                $this->db->executeStatement("INSERT INTO enrollments (user_id,course_id,source,created_at) VALUES (?,?,'free',UTC_TIMESTAMP())", [$u->id,$courseId]);
                $id = $this->db->lastInsertId();
            } return (int)$id;
        });
        return $this->mapper->map('enrollments', $this->db->fetchAssociative('SELECT * FROM enrollments WHERE id=?', [$id]));
    }
    public function purchase(int $courseId, PaymentDetailsDto $dto, string $key): object
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $key)) {
            throw new BadRequestHttpException('Idempotency-Key of 8-128 safe characters required');
        }
        $fixture = $this->scenarios->resolve($dto);
        $u = $this->access->user();
        $fingerprint = hash('sha256', json_encode([$courseId,$fixture,$dto->beneficiaryName]));
        $isNew = false;
        $id = $this->db->transactional(function () use ($u, $courseId, $key, $fixture, $dto, $fingerprint, &$isNew) {
            $this->db->fetchOne('SELECT id FROM users WHERE id=? FOR UPDATE', [$u->id]);
            $existing = $this->db->fetchAssociative('SELECT * FROM purchases WHERE user_id=? AND idempotency_key=?', [$u->id,$key]);
            if ($existing) {
                if ($existing['request_fingerprint'] !== $fingerprint) {
                    throw new ConflictHttpException('Idempotency key reused with a different request');
                } return (int)$existing['id'];
            }
            $c = $this->db->fetchAssociative('SELECT * FROM courses WHERE id=? FOR UPDATE', [$courseId]);
            if (!$c || $c['deleted_at']) {
                throw new NotFoundHttpException();
            }
            if (!$c['price']) {
                throw new ConflictHttpException('Free course uses enrollment');
            }
            if ($this->db->fetchOne('SELECT id FROM enrollments WHERE user_id=? AND course_id=?', [$u->id,$courseId])) {
                throw new ConflictHttpException('Already enrolled');
            }
            if ($this->db->fetchOne("SELECT id FROM purchases WHERE user_id=? AND course_id=? AND status IN ('pending','processing','retrying')", [$u->id,$courseId])) {
                throw new ConflictHttpException('Purchase already active');
            }
            $meta = json_encode([['fixture' => $fixture,'beneficiaryName' => $dto->beneficiaryName,'requestId' => $this->requests->getCurrentRequest()?->attributes->get('_request_id', '')]], JSON_THROW_ON_ERROR);
            $this->db->executeStatement("INSERT INTO purchases (user_id,course_id,amount,currency,status,metadata,idempotency_key,request_fingerprint,created_at,updated_at) VALUES (?,?,?,?,'pending',?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$u->id,$courseId,(int)$c['price'],$this->profile->currency,$meta,$key,$fingerprint]);
            $id = (int)$this->db->lastInsertId();
            $isNew = true;
            if ($this->profile->resilient()) {
                self::outbox($this->db, $id);
            } return $id;
        });
        if ($isNew && !$this->profile->resilient()) {
            $this->bus->dispatch(new ProcessPurchase($id, $this->requests->getCurrentRequest()?->attributes->get('_request_id', '') ?? ''));
        }
        return $this->mapper->map('purchases', $this->db->fetchAssociative('SELECT * FROM purchases WHERE id=?', [$id]));
    }
    public static function outbox(Connection $db, int $id, int $delay = 0): void
    {
        $db->executeStatement("INSERT INTO outbox (type,aggregate_id,payload,available_at,delivery_count,created_at) VALUES ('payment',?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),0,UTC_TIMESTAMP())", [$id,json_encode(['purchaseId' => $id,'requestId' => (json_decode($db->fetchOne('SELECT metadata FROM purchases WHERE id=?',[$id]),true)[0]['requestId'] ?? '')]),$delay]);
    }
}
