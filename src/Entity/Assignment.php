<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'assignments')]
class Assignment extends Record
{
    #[ORM\Column(length: 255)]
    public string $name = '';
    #[ORM\ManyToOne(targetEntity: Course::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public Course $course;
    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $deadline;
    #[ORM\Column(type: 'text')]
    public string $description = '';
    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;
    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;
    public function __construct()
    {
        parent::__construct();
        $this->deadline = $this->createdAt->modify('+7 days');
        $this->updatedAt = $this->createdAt;
    }
}
