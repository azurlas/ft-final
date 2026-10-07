<?php

declare(strict_types=1);

namespace App\Persistence;

use Doctrine\ORM\EntityManagerInterface;

/** One flush at the end of a request, command batch or transactional handler. */
final readonly class Transaction
{
    public function __construct(private EntityManagerInterface $em)
    {
    }
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        $this->em->beginTransaction();
        try {
            $result = $operation();
            $this->em->flush();
            $this->em->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->em->getConnection()->rollBack();
            $this->em->clear();
            throw $error;
        }
    }
}
