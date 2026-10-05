<?php

declare(strict_types=1);

namespace App\Payment;

final readonly class PaymentResult
{
    public function __construct(public string $status, public ?string $reference = null, public ?string $error = null)
    {
    }
}
