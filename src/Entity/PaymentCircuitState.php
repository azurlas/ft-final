<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payment_circuit')]
class PaymentCircuitState extends Record
{
    #[ORM\Column(length: 32, unique: true)]
    public string $provider = 'mock';
    #[ORM\Column(name: 'consecutive_failures')]
    public int $consecutiveFailures = 0;
    #[ORM\Column(name: 'opened_until', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $openedUntil = null;
    #[ORM\Column(name: 'probe_lease_until', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $probeLeaseUntil = null;
}
