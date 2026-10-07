<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{PaymentAttempt, CoursePurchase};

/** @extends Repository<PaymentAttempt> */
final readonly class PaymentAttemptRepository extends Repository
{
    protected const ENTITY = PaymentAttempt::class;
    public function last(CoursePurchase $purchase): ?PaymentAttempt
    {
        return $this->query()->where('e.purchase = :purchase')->setParameter('purchase', $purchase)->orderBy('e.attemptNumber', \SortDirection::Descending)->setMaxResults(1)->getQuery()->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)->getOneOrNullResult();
    }
}
