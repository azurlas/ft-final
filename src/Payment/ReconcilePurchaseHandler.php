<?php

declare(strict_types=1);

namespace App\Payment;

use App\Repository\{CoursePurchaseRepository, OutboxMessageRepository};
use App\Persistence\Transaction;
use Psr\Log\LoggerInterface;

final readonly class ReconcilePurchaseHandler
{
    public function __construct(private Transaction $transaction, private CoursePurchaseRepository $purchases, private OutboxMessageRepository $outbox, private LoggerInterface $logger)
    {
    }
    public function handle(int $id): void
    {
        $this->transaction->run(function () use ($id) {
            $purchase = $this->purchases->lock($id);
            if (!$purchase || in_array($purchase->status, ['succeeded','failed','declined'], true)) {
                return;
            }
            if (!$this->outbox->findOneBy(['purchase' => $purchase, 'publishedAt' => null])) {
                $this->outbox->schedule($purchase);
                $this->logger->notice('payment.reconciled', ['purchase_id' => $id]);
            }
        });
    }
}
