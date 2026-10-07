<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'outbox')]
#[ORM\Index(name: 'idx_outbox_due', columns: ['published_at', 'available_at', 'lease_until'])]
#[ORM\Index(name: 'idx_outbox_aggregate', columns: ['aggregate_id'])]
class OutboxMessage extends Record
{
    #[ORM\Column(length: 32)]
    public string $type = 'payment';
    #[ORM\ManyToOne(targetEntity: CoursePurchase::class)]
    #[ORM\JoinColumn(name: 'aggregate_id', nullable: false, onDelete: 'RESTRICT')]
    public CoursePurchase $purchase;
    #[ORM\Column(type: 'json')]
    public array $payload = [];
    #[ORM\Column(name: 'available_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $availableAt;
    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $publishedAt = null;
    #[ORM\Column(name: 'lease_until', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $leaseUntil = null;
    #[ORM\Column(name: 'delivery_count')]
    public int $deliveryCount = 0;
    public function __construct()
    {
        parent::__construct();
        $this->availableAt = $this->createdAt;
    }
}
