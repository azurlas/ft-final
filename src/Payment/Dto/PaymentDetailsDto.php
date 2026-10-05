<?php

declare(strict_types=1);

namespace App\Payment\Dto;

/** Synthetic payment input. Never serialize this DTO into logs, queues, or purchase metadata. */
final class PaymentDetailsDto
{
    public string $beneficiaryName = '';
    public string $cardNumber = '';
    public string $expiryDate = '';
    public string $cvv = '';
}
