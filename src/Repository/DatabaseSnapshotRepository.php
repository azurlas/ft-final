<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{User, Course, Assignment, CourseEnrollment, CoursePurchase, PaymentAttempt, Submission, Grade, MockPaymentReceipt};
use Doctrine\ORM\EntityManagerInterface;

/** Ordered entity snapshots for isolated restore verification, using ORM metadata. */
final readonly class DatabaseSnapshotRepository
{
    private const ENTITIES = [User::class, Course::class, Assignment::class, CourseEnrollment::class, CoursePurchase::class, PaymentAttempt::class, Submission::class, Grade::class, MockPaymentReceipt::class];
    public function __construct(private EntityManagerInterface $em)
    {
    }
    public function snapshot(): array
    {
        $counts = $hashes = [];
        foreach (self::ENTITIES as $class) {
            $metadata = $this->em->getClassMetadata($class);
            $table = $metadata->getTableName();
            $rows = [];
            foreach ($this->em->getRepository($class)->findBy([], ['id' => 'ASC']) as $entity) {
                $row = [];
                foreach ($metadata->getFieldNames() as $field) {
                    $value = $metadata->getFieldValue($entity, $field);
                    $row[$metadata->getColumnName($field)] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
                }
                foreach ($metadata->getAssociationMappings() as $field => $mapping) {
                    if (!$mapping->isToOneOwningSide()) {
                        continue;
                    }
                    $related = $metadata->getFieldValue($entity, $field);
                    $row[$mapping->joinColumns[0]->name] = $related?->id;
                }
                ksort($row);
                $rows[] = $row;
            }
            $counts[$table] = count($rows);
            $hashes[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }
        return ['counts' => $counts, 'hashes' => $hashes];
    }
    public function violations(): int
    {
        $queries = [
            'SELECT COUNT(p.id) FROM App\Entity\CoursePurchase p WHERE p.status = :status AND NOT EXISTS (SELECT e.id FROM App\Entity\CourseEnrollment e WHERE e.purchase = p)',
            'SELECT COUNT(g.id) FROM App\Entity\Grade g WHERE g.grade < 0 OR g.grade > 100',
            'SELECT COUNT(s.id) FROM App\Entity\Submission s WHERE s.sizeBytes > 10000000',
        ];
        $total = 0;
        foreach ($queries as $index => $dql) {
            $query = $this->em->createQuery($dql);
            if ($index === 0) {
                $query->setParameter('status', 'succeeded');
            }
            $total += (int)$query->getSingleScalarResult();
        }
        return $total;
    }
}
