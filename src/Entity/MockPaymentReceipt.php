<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mock_receipts')]
#[ORM\UniqueConstraint(name: 'uniq_mock_receipts_0', columns: ['purchase_id'])]
class MockPaymentReceipt extends Record
{
    #[ORM\ManyToOne(targetEntity: CoursePurchase::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public CoursePurchase $purchase;
    #[ORM\Column(name: 'provider_reference', length: 100, unique: true)]
    public string $providerReference = '';
    #[ORM\Column]
    public int $amount = 0;
    #[ORM\Column(length: 3)]
    public string $currency = 'USD';
}
