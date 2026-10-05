<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payment_attempts')]
#[ORM\UniqueConstraint(name: 'uniq_payment_attempts_0', columns: ['purchase_id', 'attempt_number'])]
#[ORM\Index(name: 'idx_attempt_due', columns: ['next_attempt_at', 'status'])]
class PaymentAttempt extends Record
{
    #[ORM\ManyToOne(targetEntity: CoursePurchase::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public CoursePurchase $purchase;
    #[ORM\Column(name: 'attempt_number')]
    public int $attemptNumber = 1;
    #[ORM\Column(length: 24)]
    public string $status = 'started';
    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $startedAt;
    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $finishedAt = null;
    #[ORM\Column(name: 'next_attempt_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $nextAttemptAt = null;
    #[ORM\Column(name: 'provider_reference', length: 100, nullable: true)]
    public ?string $providerReference = null;
    #[ORM\Column(name: 'error_code', length: 100, nullable: true)]
    public ?string $errorCode = null;
    #[ORM\Column(name: 'duration_ms')]
    public int $durationMs = 0;
    public function __construct()
    {
        parent::__construct();
        $this->startedAt = $this->createdAt;
    }
}
