<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'enrollments')]
#[ORM\UniqueConstraint(name: 'uniq_enrollments_0', columns: ['user_id', 'course_id'])]
class CourseEnrollment extends Record
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public User $user;
    #[ORM\ManyToOne(targetEntity: Course::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    public Course $course;
    #[ORM\Column(length: 16)]
    public string $source = 'free';
    #[ORM\ManyToOne(targetEntity: CoursePurchase::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    public ?CoursePurchase $purchase = null;
}
