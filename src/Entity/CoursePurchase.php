<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'purchases')]
#[ORM\UniqueConstraint(name: 'uniq_purchases_0', columns: ['user_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_purchase_active', columns: ['user_id', 'course_id', 'status'])]
class CoursePurchase extends Record
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public User $user;
    #[ORM\ManyToOne(targetEntity: Course::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public Course $course;
    #[ORM\Column]
    public int $amount = 0;
    #[ORM\Column(length: 3)]
    public string $currency = 'USD';
    #[ORM\Column(length: 24)]
    public string $status = 'pending';
    #[ORM\Column(type: 'json')]
    public array $metadata = [];
    #[ORM\Column(name: 'idempotency_key', length: 128)]
    public string $idempotencyKey = '';
    #[ORM\Column(name: 'request_fingerprint', length: 64)]
    public string $requestFingerprint = '';
    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;
    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $completedAt = null;
    #[ORM\Column(name: 'processing_lease_until', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $processingLeaseUntil = null;
    public function __construct()
    {
        parent::__construct();
        $this->updatedAt = $this->createdAt;
    }
}
