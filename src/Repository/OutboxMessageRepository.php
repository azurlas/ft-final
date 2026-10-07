<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{OutboxMessage, CoursePurchase};

/** @extends Repository<OutboxMessage> */
final readonly class OutboxMessageRepository extends Repository
{
    protected const ENTITY = OutboxMessage::class;
    public function schedule(CoursePurchase $purchase, int $delay = 0): OutboxMessage
    {
        $message = new OutboxMessage();
        $message->purchase = $purchase;
        $message->payload = ['requestId' => $purchase->metadata[0]['requestId'] ?? ''];
        $message->availableAt = new \DateTimeImmutable('+' . $delay . ' seconds');
        $this->save($message);
        return $message;
    }
    public function due(): ?OutboxMessage
    {
        return $this->query()->where('e.publishedAt IS NULL AND e.availableAt <= :now AND (e.leaseUntil IS NULL OR e.leaseUntil < :now)')->setParameter('now', new \DateTimeImmutable())->orderBy('e.id', \SortDirection::Ascending)->setMaxResults(1)->getQuery()->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE)->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)->getOneOrNullResult();
    }
}
