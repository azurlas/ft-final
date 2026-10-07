<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class Repositories
{
    public function __construct(
        private UserRepository $users,
        private CourseRepository $courses,
        private AssignmentRepository $assignments,
        private GradeRepository $grades,
        private CourseEnrollmentRepository $enrollments,
        private CoursePurchaseRepository $purchases,
        private SubmissionRepository $submissions,
    ) {
    }
    public function for(string $kind): Repository
    {
        return match ($kind) {
            'users' => $this->users, 'courses' => $this->courses,
            'assignments' => $this->assignments, 'grades' => $this->grades,
            'enrollments' => $this->enrollments, 'purchases' => $this->purchases,
            'submissions' => $this->submissions,
            default => throw new \LogicException('Unknown resource kind'),
        };
    }
}
