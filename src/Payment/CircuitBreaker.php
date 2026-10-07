<?php

declare(strict_types=1);

namespace App\Payment;

use App\Repository\PaymentCircuitStateRepository;
use App\RuntimeProfile;

/** Circuit changes join the claim/settlement handler's transaction and final flush. */
final readonly class CircuitBreaker
{
    public function __construct(private PaymentCircuitStateRepository $states, private RuntimeProfile $profile)
    {
    }
    public function allow(): bool
    {
        if (!$this->profile->resilient()) {
            return true;
        }
        $state = $this->states->locked();
        if (!$state || !$state->openedUntil) {
            return true;
        }
        $now = new \DateTimeImmutable();
        if ($state->openedUntil > $now || ($state->probeLeaseUntil && $state->probeLeaseUntil > $now)) {
            return false;
        }
        $state->probeLeaseUntil = $now->modify('+10 seconds');
        return true;
    }
    public function record(bool $transient): void
    {
        if (!$this->profile->resilient()) {
            return;
        }
        $state = $this->states->locked() ?? throw new \LogicException('Payment circuit is not initialized');
        $state->consecutiveFailures = $transient ? $state->consecutiveFailures + 1 : 0;
        $state->openedUntil = $state->consecutiveFailures >= 5 ? new \DateTimeImmutable('+15 seconds') : null;
        $state->probeLeaseUntil = null;
    }
}
