<?php

declare(strict_types=1);

namespace App\Payment;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\LoggerInterface;

/** Explicit local experiment checkpoints; empty by default in all Helm profiles. */
final readonly class FailureHooks
{
    public function __construct(#[Autowire('%env(EXPERIMENT_HOOK)%')] private string $hook, private LoggerInterface $logger)
    {
    }
    public function checkpoint(string $name, int $purchaseId): void
    {
        if ($this->hook !== $name) {
            return;
        } $this->logger->notice('experiment.checkpoint', ['checkpoint' => $name,'purchase_id' => $purchaseId]);
        sleep(30);
    }
}
