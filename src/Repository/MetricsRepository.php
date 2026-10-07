<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{CoursePurchase, OutboxMessage, PaymentAttempt, PaymentCircuitState};
use Doctrine\ORM\EntityManagerInterface;

final readonly class MetricsRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }
    public function render(): string
    {
        $purchases = $this->em->createQueryBuilder()->select('p.status, COUNT(p.id) AS n')->from(CoursePurchase::class, 'p')->groupBy('p.status')->getQuery()->getArrayResult();
        $pending = $this->em->createQueryBuilder()->select('COUNT(o.id)')->from(OutboxMessage::class, 'o')->where('o.publishedAt IS NULL')->getQuery()->getSingleScalarResult();
        $oldest = $this->em->createQueryBuilder()->select('MIN(p.createdAt)')->from(CoursePurchase::class, 'p')->where('p.status IN (:statuses)')->setParameter('statuses', ['pending','processing','retrying'])->getQuery()->getSingleScalarResult();
        $age = $oldest ? max(0, time() - (new \DateTimeImmutable($oldest, new \DateTimeZone('UTC')))->getTimestamp()) : 0;
        $attempts = $this->em->createQueryBuilder()->select('a.status, COUNT(a.id) AS n, SUM(a.durationMs) AS milliseconds')->from(PaymentAttempt::class, 'a')->groupBy('a.status')->getQuery()->getArrayResult();
        $open = $this->em->createQueryBuilder()->select('COUNT(c.id)')->from(PaymentCircuitState::class, 'c')->where('c.openedUntil > :now')->setParameter('now', new \DateTimeImmutable())->getQuery()->getSingleScalarResult();
        $text = "university_database_up 1\n";
        foreach ($purchases as $row) {
            $text .= 'university_purchases{status="'.$row['status'].'"} '.$row['n']."\n";
        }
        $text .= 'university_outbox_pending '.$pending."\n";
        $text .= 'university_purchase_oldest_pending_seconds '.$age."\n";
        foreach ($attempts as $row) {
            $text .= 'university_payment_attempts_total{status="'.$row['status'].'"} '.$row['n']."\n";
            $text .= 'university_payment_duration_seconds_sum{status="'.$row['status'].'"} '.($row['milliseconds'] / 1000)."\n";
        }
        return $text.'university_circuit_open '.$open."\n";
    }
}
