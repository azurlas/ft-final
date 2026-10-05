<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'grades')]
#[ORM\UniqueConstraint(name: 'uniq_grades_0', columns: ['assignment_id', 'user_id'])]
class Grade extends Record
{
    #[ORM\ManyToOne(targetEntity: Assignment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public Assignment $assignment;
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public User $user;
    #[ORM\Column]
    public int $grade = 0;
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $comment = null;
    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;
    public function __construct()
    {
        parent::__construct();
        $this->updatedAt = $this->createdAt;
    }
}
