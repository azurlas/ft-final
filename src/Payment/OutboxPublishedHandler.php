<?php

declare(strict_types=1);

namespace App\Payment;

use App\Repository\OutboxMessageRepository;
use App\Persistence\Transaction;

final readonly class OutboxPublishedHandler
{
    public function __construct(private Transaction $transaction, private OutboxMessageRepository $outbox)
    {
    }
    public function handle(int $id): void
    {
        $this->transaction->run(function () use ($id) {
            $message = $this->outbox->lock($id);
            if ($message && !$message->publishedAt) {
                $message->publishedAt = new \DateTimeImmutable();
                $message->leaseUntil = null;
            }
        });
    }
}
