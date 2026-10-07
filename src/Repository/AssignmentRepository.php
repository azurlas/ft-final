<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{Assignment};

/** @extends Repository<Assignment> */
final readonly class AssignmentRepository extends Repository
{
    protected const ENTITY = Assignment::class;
    public function active(int $id): ?Assignment
    {
        return $this->query()->join('e.course', 'c')->where('e.id = :id AND e.deletedAt IS NULL AND c.deletedAt IS NULL')->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }
}
