<?php

declare(strict_types=1);

namespace App\Api\State;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException, NotFoundHttpException};

final class Access
{
    public function __construct(private Security $security, private Connection $db)
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
    public function enrolled(int $courseId): void
    {
        $u = $this->user();
        if (!$u->isAdmin && !$this->db->fetchOne('SELECT id FROM enrollments WHERE user_id=? AND course_id=?', [$u->id, $courseId])) {
            throw new AccessDeniedHttpException('Course enrollment required');
        }
    }
}
