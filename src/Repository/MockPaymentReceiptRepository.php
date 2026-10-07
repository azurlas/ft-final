<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{MockPaymentReceipt};

/** @extends Repository<MockPaymentReceipt> */
final readonly class MockPaymentReceiptRepository extends Repository
{
    protected const ENTITY = MockPaymentReceipt::class;

}
