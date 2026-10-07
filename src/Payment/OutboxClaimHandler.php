<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\OutboxMessage;
use App\Repository\OutboxMessageRepository;
use App\Persistence\Transaction;

final readonly class OutboxClaimHandler
{
    public function __construct(private Transaction $transaction, private OutboxMessageRepository $outbox)
    {
    }
    public function handle(): ?OutboxMessage
    {
        return $this->transaction->run(function () {
            $message = $this->outbox->due();
            if ($message) {
                $message->leaseUntil = new \DateTimeImmutable('+10 seconds');
                ++$message->deliveryCount;
            }
            return $message;
        });
    }
}
