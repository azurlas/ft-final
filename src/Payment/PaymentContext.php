<?php

declare(strict_types=1);

namespace App\Payment;

final readonly class PaymentContext
{
    public function __construct(public int $purchaseId, public int $attemptNumber, public int $amount, public string $currency)
    {
    }
}
