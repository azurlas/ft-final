<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\PaymentAttempt;
use App\Repository\{UserRepository, CourseRepository, CoursePurchaseRepository, PaymentAttemptRepository};
use App\Persistence\Transaction;
use App\RuntimeProfile;

/** The attempt/lease is durable before any network payment call. */
final readonly class ClaimPurchaseHandler
{
    public function __construct(private Transaction $transaction, private UserRepository $users, private CourseRepository $courses, private CoursePurchaseRepository $purchases, private PaymentAttemptRepository $attempts, private CircuitBreaker $circuit, private RuntimeProfile $profile)
    {
    }
    public function handle(int $purchaseId): ?PaymentAttempt
    {
        return $this->transaction->run(function () use ($purchaseId) {
            $purchase = $this->purchases->find($purchaseId);
            if (!$purchase || in_array($purchase->status, ['succeeded','failed','declined'], true)) {
                return null;
            }
            $this->users->lock($purchase->user->id);
            $this->courses->lock($purchase->course->id);
            $purchase = $this->purchases->lock($purchaseId);
            $now = new \DateTimeImmutable();
            if (in_array($purchase->status, ['succeeded','failed','declined'], true) || ($purchase->processingLeaseUntil && $purchase->processingLeaseUntil > $now)) {
                return null;
            }
            if (!$this->circuit->allow()) {
                return null;
            }
            $last = $this->attempts->last($purchase);
            if ($last?->nextAttemptAt && $last->nextAttemptAt > $now) {
                return null;
            }
            $number = $last && $last->status === 'started' ? $last->attemptNumber : ($last ? $last->attemptNumber + 1 : 1);
            if ($number > $this->profile->maxAttempts()) {
                return null;
            }
            if (!$last || $last->status !== 'started') {
                $last = new PaymentAttempt();
                $last->purchase = $purchase;
                $last->attemptNumber = $number;
                $this->attempts->save($last);
            }
            $purchase->status = 'processing';
            $purchase->processingLeaseUntil = $now->modify('+15 seconds');
            $purchase->updatedAt = $now;
            return $last;
        });
    }
}
