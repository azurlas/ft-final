<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{CourseEnrollment, Course, Assignment, Submission, Grade};

/** @extends Repository<CourseEnrollment> */
final readonly class CourseEnrollmentRepository extends Repository
{
    protected const ENTITY = CourseEnrollment::class;
    public function reportRows(Course $course): array
    {
        return $this->query()->select('u.username, u.login, a.name AS assignment_name, a.deadline, s.submittedAt AS submitted_at, g.grade, g.comment')->join('e.user', 'u')->leftJoin(Assignment::class, 'a', 'WITH', 'a.course = e.course')->leftJoin(Submission::class, 's', 'WITH', 's.assignment = a AND s.user = u')->leftJoin(Grade::class, 'g', 'WITH', 'g.assignment = a AND g.user = u')->where('e.course = :course')->setParameter('course', $course)->orderBy('u.id', \SortDirection::Ascending)->addOrderBy('a.id', \SortDirection::Ascending)->getQuery()->getArrayResult();
    }
    public function reportAverages(Course $course): array
    {
        return $this->query()->select('u.username, AVG(g.grade) AS average_grade, COUNT(g.id) AS graded_count')->join('e.user', 'u')->leftJoin(Assignment::class, 'a', 'WITH', 'a.course = e.course')->leftJoin(Grade::class, 'g', 'WITH', 'g.assignment = a AND g.user = u')->where('e.course = :course')->setParameter('course', $course)->groupBy('u.id, u.username')->orderBy('u.id', \SortDirection::Ascending)->getQuery()->getArrayResult();
    }
}
