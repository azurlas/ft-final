<?php

declare(strict_types=1);

namespace App\Payment\Message;

final readonly class ProcessPurchase
{
    public function __construct(public int $purchaseId, public string $requestId = '')
    {
    }
}
