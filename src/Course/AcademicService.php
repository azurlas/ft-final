<?php

declare(strict_types=1);

namespace App\Course;

use App\Api\State\{Access};
use App\Entity\{Course,Assignment,Grade};
use App\Repository\{Repositories,CourseRepository,AssignmentRepository,UserRepository,CoursePurchaseRepository,SubmissionRepository,GradeRepository};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,ConflictHttpException,NotFoundHttpException};

final readonly class AcademicService
{
    public function __construct(private Repositories $repositories, private CourseRepository $courses, private AssignmentRepository $assignments, private UserRepository $users, private CoursePurchaseRepository $purchases, private SubmissionRepository $submissions, private GradeRepository $grades, private Access $access)
    {
    }
    public function mutate(string $kind, string $method, ?int $id, array $data): ?object
    {
        $this->access->admin();
        $classes = ['courses' => Course::class,'assignments' => Assignment::class,'grades' => Grade::class];
        $entity = $id ? $this->repositories->for($kind)->find($id) : new ($classes[$kind])();
        if (!$entity || (property_exists($entity, 'deletedAt') && $entity->deletedAt !== null)) {
            throw new NotFoundHttpException();
        }

        if ($kind === 'courses' && $id) {
            $this->courses->lock($id);
            if ($this->purchases->activeForCourse($id)) {
                throw new ConflictHttpException('Course has an active purchase');
            }
        }
        if ($kind === 'assignments' && $id) {
            $this->courses->lock($entity->course->id);
            $this->assignments->lock($id);
        }
        if ($id && $kind === 'grades' && $method === 'DELETE') {
            $this->grades->lock($id) ?? throw new NotFoundHttpException();
        }
        if (property_exists($entity, 'deletedAt') && $entity->deletedAt !== null) {
            throw new NotFoundHttpException();
        }
        if ($method === 'DELETE') {
            if ($kind === 'grades') {
                $this->repositories->for($kind)->remove($entity);
            } else {
                $entity->deletedAt = new \DateTimeImmutable();
            }
        } elseif ($kind === 'courses') {
            if (array_key_exists('name', $data)) {
                $entity->name = $this->text($data['name'], 255);
            }
            if (array_key_exists('price', $data)) {
                if ($data['price'] !== null && (!is_int($data['price']) || $data['price'] < 0)) {
                    throw new BadRequestHttpException('price must be null or a nonnegative integer');
                } $entity->price = $data['price'];
            }
            if (!$entity->name) {
                throw new BadRequestHttpException('name required');
            }
        } elseif ($kind === 'assignments') {
            if (array_key_exists('courseId', $data)) {
                if ($id && (int)$data['courseId'] !== $entity->course->id) {
                    throw new ConflictHttpException('Assignment course cannot change');
                } $entity->course = $this->activeCourse((int)$data['courseId']);
            }
            if (!isset($entity->course)) {
                throw new BadRequestHttpException('courseId required');
            }
            $this->courses->lock($entity->course->id);
            if ($entity->course->deletedAt) {
                throw new ConflictHttpException('Course archived');
            }
            if (isset($data['name'])) {
                $entity->name = $this->text($data['name'], 255);
            }
            if (!$entity->name) {
                throw new BadRequestHttpException('name required');
            }
            if (isset($data['description'])) {
                $entity->description = $this->text($data['description'], 10000, false);
            }
            if (isset($data['deadline'])) {
                try {
                    if (!preg_match('/(Z|[+-]\d{2}:\d{2})$/', $data['deadline'])) {
                        throw new \Exception();
                    } $entity->deadline = (new \DateTimeImmutable($data['deadline']))->setTimezone(new \DateTimeZone('UTC'));
                } catch (\Throwable) {
                    throw new BadRequestHttpException('deadline must be ISO 8601 with timezone');
                }
            } elseif (!$id) {
                throw new BadRequestHttpException('deadline required');
            }
        } elseif ($kind === 'grades') {
            if (isset($data['assignmentId'])) {
                if ($id && $entity->assignment->id !== (int)$data['assignmentId']) {
                    throw new ConflictHttpException('Grade assignment cannot change');
                } $entity->assignment = $this->assignments->find((int)$data['assignmentId']) ?? throw new NotFoundHttpException();
            }
            if (isset($data['userId'])) {
                if ($id && $entity->user->id !== (int)$data['userId']) {
                    throw new ConflictHttpException('Grade student cannot change');
                } $entity->user = $this->users->find((int)$data['userId']) ?? throw new NotFoundHttpException();
            }
            if (!isset($entity->assignment,$entity->user)) {
                throw new BadRequestHttpException('assignmentId and userId required');
            }
            $this->users->lock($entity->user->id);
            $this->courses->lock($entity->assignment->course->id);
            $this->assignments->lock($entity->assignment->id);
            if ($id) {
                $this->grades->lock($id) ?? throw new NotFoundHttpException();
            }
            if ($entity->assignment->deletedAt || $entity->assignment->course->deletedAt) {
                throw new ConflictHttpException('Assignment archived');
            }
            if (!$this->submissions->findOneBy(['assignment' => $entity->assignment, 'user' => $entity->user])) {
                throw new ConflictHttpException('Submission required before grading');
            }
            if (array_key_exists('grade', $data)) {
                if (!is_int($data['grade']) || $data['grade'] < 0 || $data['grade'] > 100) {
                    throw new BadRequestHttpException('grade must be an integer from 0 to 100');
                } $entity->grade = $data['grade'];
            } elseif (!$id) {
                throw new BadRequestHttpException('grade required');
            }
            if (array_key_exists('comment', $data)) {
                $entity->comment = $data['comment'] === null ? null : $this->text($data['comment'], 10000, false);
            }
            if (!$id && $this->grades->findOneBy(['assignment' => $entity->assignment, 'user' => $entity->user])) {
                throw new ConflictHttpException('Grade already exists');
            }
        }
        $entity->updatedAt = new \DateTimeImmutable();
        if ($method !== 'DELETE') {
            $this->repositories->for($kind)->save($entity);
        }
        return $method === 'DELETE' ? null : $entity;
    }
    public function activeCourse(int $id): Course
    {
        $c = $this->courses->find($id);
        if (!$c || $c->deletedAt) {
            throw new NotFoundHttpException();
        } return $c;
    }
    private function text(mixed $v, int $max, bool $required = true): string
    {
        if (!is_string($v) || mb_strlen($v) > $max || ($required && !trim($v))) {
            throw new BadRequestHttpException('Invalid text field');
        } return trim($v);
    }
}
