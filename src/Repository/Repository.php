<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\{EntityManagerInterface, QueryBuilder};
use Doctrine\DBAL\LockMode;
use App\Entity\{Course, Assignment};

/** @template T of object */
abstract readonly class Repository
{
    protected const ENTITY = '';
    public function __construct(protected EntityManagerInterface $em)
    {
    }
    /** @return T|null */
    public function find(int $id): ?object
    {
        return $this->em->find(static::ENTITY, $id);
    }
    /** @return T|null */
    public function findOneBy(array $criteria): ?object
    {
        return $this->em->getRepository(static::ENTITY)->findOneBy($criteria);
    }
    /** @return list<T> */
    public function findBy(array $criteria = [], array $order = ['id' => 'ASC']): array
    {
        return $this->em->getRepository(static::ENTITY)->findBy($criteria, $order);
    }
    /** @param T $entity */
    public function save(object $entity): void
    {
        $this->em->persist($entity);
    }
    /** @param T $entity */
    public function remove(object $entity): void
    {
        $this->em->remove($entity);
    }
    /** @return T|null */
    public function lock(int $id): ?object
    {
        return $this->query()->where('e.id = :id')->setParameter('id', $id)->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)->getOneOrNullResult();
    }
    protected function query(): QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('e')->from(static::ENTITY, 'e');
    }
    /** @return list<T> */
    public function visible(?int $id, ?int $userId, ?int $courseId, int $page): array
    {
        $q = $this->query()->orderBy('e.id', \SortDirection::Ascending);
        if (is_a(static::ENTITY, Course::class, true) || is_a(static::ENTITY, Assignment::class, true)) {
            $q->andWhere('e.deletedAt IS NULL');
        }
        if (static::ENTITY === Assignment::class) {
            $q->join('e.course', 'c')->andWhere('c.deletedAt IS NULL');
            if ($courseId !== null) {
                $q->andWhere('c.id = :course')->setParameter('course', $courseId);
            }
        }
        if ($userId !== null) {
            $q->andWhere('IDENTITY(e.user) = :user')->setParameter('user', $userId);
        }
        if ($id !== null) {
            $q->andWhere('e.id = :id')->setParameter('id', $id);
        } else {
            $q->setMaxResults(20)->setFirstResult(($page - 1) * 20);
        }
        return $q->getQuery()->getResult();
    }
}
