<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'submissions')]
#[ORM\UniqueConstraint(name: 'uniq_submissions_0', columns: ['assignment_id', 'user_id'])]
class Submission extends Record
{
    #[ORM\ManyToOne(targetEntity: Assignment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public Assignment $assignment;
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public User $user;
    #[ORM\Column(name: 'object_key', length: 255, unique: true)]
    public string $objectKey = '';
    #[ORM\Column(name: 'original_filename', length: 255)]
    public string $originalFilename = '';
    #[ORM\Column(name: 'content_type', length: 128)]
    public string $contentType = '';
    #[ORM\Column(name: 'size_bytes')]
    public int $sizeBytes = 0;
    #[ORM\Column(length: 64)]
    public string $sha256 = '';
    #[ORM\Column(name: 'submitted_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $submittedAt;
    public function __construct()
    {
        parent::__construct();
        $this->submittedAt = $this->createdAt;
    }
}
