<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{CoursePurchase};

/** @extends Repository<CoursePurchase> */
final readonly class CoursePurchaseRepository extends Repository
{
    protected const ENTITY = CoursePurchase::class;
    public function activeForCourse(int $courseId, ?int $userId = null): bool
    {
        $q = $this->query()->select('COUNT(e.id)')->where('IDENTITY(e.course) = :course AND e.status IN (:statuses)')->setParameter('course', $courseId)->setParameter('statuses', ['pending', 'processing', 'retrying']);
        if ($userId !== null) {
            $q->andWhere('IDENTITY(e.user) = :user')->setParameter('user', $userId);
        }
        return (int)$q->getQuery()->getSingleScalarResult() > 0;
    }
    /** @return list<CoursePurchase> */
    public function abandoned(): array
    {
        $q = $this->query()->where('e.status IN (:statuses)')->andWhere('e.processingLeaseUntil IS NULL OR e.processingLeaseUntil < :now')->andWhere('NOT EXISTS (SELECT o.id FROM App\\Entity\\OutboxMessage o WHERE o.purchase = e AND (o.publishedAt IS NULL OR o.publishedAt > :recent))')->setParameter('statuses', ['pending', 'processing', 'retrying'])->setParameter('now', new \DateTimeImmutable())->setParameter('recent', new \DateTimeImmutable('-10 seconds'))->setMaxResults(100);
        return $q->getQuery()->getResult();
    }
}
