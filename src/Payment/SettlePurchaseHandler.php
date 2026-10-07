<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\CourseEnrollment;
use App\Repository\{UserRepository, CourseRepository, CoursePurchaseRepository, PaymentAttemptRepository, CourseEnrollmentRepository, OutboxMessageRepository};
use App\Persistence\Transaction;
use App\RuntimeProfile;

final readonly class SettlePurchaseHandler
{
    public function __construct(private Transaction $transaction, private UserRepository $users, private CourseRepository $courses, private CoursePurchaseRepository $purchases, private PaymentAttemptRepository $attempts, private CourseEnrollmentRepository $enrollments, private OutboxMessageRepository $outbox, private CircuitBreaker $circuit, private RuntimeProfile $profile)
    {
    }
    public function handle(int $purchaseId, int $attemptNumber, PaymentResult $result, int $duration): bool
    {
        return $this->transaction->run(function () use ($purchaseId, $attemptNumber, $result, $duration) {
            $purchase = $this->purchases->find($purchaseId);
            if (!$purchase) {
                return false;
            }
            $this->users->lock($purchase->user->id);
            $this->courses->lock($purchase->course->id);
            $purchase = $this->purchases->lock($purchaseId);
            if (in_array($purchase->status, ['succeeded','failed','declined'], true)) {
                return false;
            }
            $attempt = $this->attempts->findOneBy(['purchase' => $purchase, 'attemptNumber' => $attemptNumber]);
            if (!$attempt || $this->attempts->lock($attempt->id)->status !== 'started') {
                return false;
            }
            $now = new \DateTimeImmutable();
            $retry = $result->status === 'transient' && $attemptNumber < $this->profile->maxAttempts();
            $delay = 2 ** $attemptNumber;
            $attempt->status = $result->status;
            $attempt->providerReference = $result->reference;
            $attempt->errorCode = $result->error;
            $attempt->durationMs = $duration;
            $attempt->finishedAt = $now;
            $attempt->nextAttemptAt = $retry ? $now->modify('+' . $delay . ' seconds') : null;
            $purchase->status = $retry ? 'retrying' : ($result->status === 'transient' ? 'failed' : $result->status);
            $purchase->processingLeaseUntil = null;
            $purchase->updatedAt = $now;
            $purchase->completedAt = $retry ? null : $now;
            if ($result->status === 'succeeded') {
                $enrollment = new CourseEnrollment();
                $enrollment->user = $purchase->user;
                $enrollment->course = $purchase->course;
                $enrollment->purchase = $purchase;
                $enrollment->source = 'paid';
                $this->enrollments->save($enrollment);
            }
            if ($retry) {
                $this->outbox->schedule($purchase, $delay);
            }
            $this->circuit->record($result->status === 'transient');
            return true;
        });
    }
}
