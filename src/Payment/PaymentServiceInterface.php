<?php

declare(strict_types=1);

namespace App\Payment;

use App\Payment\Dto\PaymentDetailsDto;

interface PaymentServiceInterface
{
    public function pay(PaymentDetailsDto $details, PaymentContext $context): PaymentResult;
}
