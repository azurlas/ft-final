<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'courses')]
class Course extends Record
{
    #[ORM\Column(length: 255)]
    public string $name = '';
    #[ORM\Column(nullable: true)]
    public ?int $price = null;
    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;
    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;
    public function __construct()
    {
        parent::__construct();
        $this->updatedAt = $this->createdAt;
    }
}
