<?php

declare(strict_types=1);

namespace App\Api\State;

use App\Entity\User;
use App\Repository\CourseEnrollmentRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException, NotFoundHttpException};

final readonly class Access
{
    public function __construct(private Security $security, private CourseEnrollmentRepository $enrollments)
    {
    }
    public function user(): User
    {
        $u = $this->security->getUser();
        if (!$u instanceof User) {
            throw new AccessDeniedHttpException('Authentication required');
        } return $u;
    }
    public function admin(): void
    {
        if (!$this->user()->isAdmin) {
            throw new AccessDeniedHttpException('Administrator required');
        }
    }
    public function owner(int $id): void
    {
        $u = $this->user();
        if (!$u->isAdmin && $u->id !== $id) {
            throw new NotFoundHttpException();
        }
    }
    public function enrolled(int $courseId, bool $allowAdmin = true): void
    {
        $u = $this->user();
        if ((!$allowAdmin || !$u->isAdmin) && !$this->enrollments->findOneBy(['user' => $u, 'course' => $courseId])) {
            throw new AccessDeniedHttpException('Course enrollment required');
        }
    }
}
