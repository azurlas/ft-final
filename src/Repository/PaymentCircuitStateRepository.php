<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{PaymentCircuitState};

/** @extends Repository<PaymentCircuitState> */
final readonly class PaymentCircuitStateRepository extends Repository
{
    protected const ENTITY = PaymentCircuitState::class;
    public function locked(): ?PaymentCircuitState
    {
        return $this->query()->where('e.provider = :provider')->setParameter('provider', 'mock')->getQuery()->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE)->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)->getOneOrNullResult();
    }
}
