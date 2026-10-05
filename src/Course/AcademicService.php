<?php

declare(strict_types=1);

namespace App\Course;

use App\Api\State\{Access,Mapper};
use App\Entity\{Course,Assignment,Grade,User};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,ConflictHttpException,NotFoundHttpException};

final class AcademicService
{
    public function __construct(private EntityManagerInterface $em, private Connection $db, private Access $access, private Mapper $mapper, private CatalogueCache $cache)
    {
    }
    public function mutate(string $kind, string $method, ?int $id, array $data): ?object
    {
        $this->access->admin();
        $classes = ['courses' => Course::class,'assignments' => Assignment::class,'grades' => Grade::class];
        $entity = $id ? $this->em->find($classes[$kind], $id) : new ($classes[$kind])();
        if (!$entity || (property_exists($entity, 'deletedAt') && $entity->deletedAt !== null)) {
            throw new NotFoundHttpException();
        }
        return $this->db->transactional(function () use ($kind, $method, $entity, $data, $id) {
            if ($kind === 'courses' && $id) {
                $this->db->fetchOne('SELECT id FROM courses WHERE id=? FOR UPDATE', [$id]);
                if ($this->db->fetchOne("SELECT id FROM purchases WHERE course_id=? AND status IN ('pending','processing','retrying') LIMIT 1", [$id])) {
                    throw new ConflictHttpException('Course has an active purchase');
                }
            }
            if ($kind === 'assignments' && $id) {
                $this->db->fetchOne('SELECT id FROM courses WHERE id=? FOR UPDATE', [$entity->course->id]);
                $this->db->fetchOne('SELECT id FROM assignments WHERE id=? FOR UPDATE', [$id]);
            }
            if ($method === 'DELETE') {
                if ($kind === 'grades') {
                    $this->em->remove($entity);
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
                $this->db->fetchOne('SELECT id FROM courses WHERE id=? FOR UPDATE', [$entity->course->id]);
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
                    } $entity->assignment = $this->em->find(Assignment::class, (int)$data['assignmentId']) ?? throw new NotFoundHttpException();
                }
                if (isset($data['userId'])) {
                    if ($id && $entity->user->id !== (int)$data['userId']) {
                        throw new ConflictHttpException('Grade student cannot change');
                    } $entity->user = $this->em->find(User::class, (int)$data['userId']) ?? throw new NotFoundHttpException();
                }
                if (!isset($entity->assignment,$entity->user)) {
                    throw new BadRequestHttpException('assignmentId and userId required');
                }
                $this->db->fetchOne('SELECT id FROM users WHERE id=? FOR UPDATE', [$entity->user->id]);
                $this->db->fetchOne('SELECT id FROM courses WHERE id=? FOR UPDATE', [$entity->assignment->course->id]);
                $this->db->fetchOne('SELECT id FROM assignments WHERE id=? FOR UPDATE', [$entity->assignment->id]);
                $this->em->refresh($entity->assignment);
                $this->em->refresh($entity->assignment->course);
                if ($entity->assignment->deletedAt || $entity->assignment->course->deletedAt) {
                    throw new ConflictHttpException('Assignment archived');
                }
                if (!$this->db->fetchOne('SELECT id FROM submissions WHERE assignment_id=? AND user_id=?', [$entity->assignment->id,$entity->user->id])) {
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
                if (!$id && $this->db->fetchOne('SELECT id FROM grades WHERE assignment_id=? AND user_id=?', [$entity->assignment->id,$entity->user->id])) {
                    throw new ConflictHttpException('Grade already exists');
                }
            }
            $entity->updatedAt = new \DateTimeImmutable();
            if ($method !== 'DELETE') {
                $this->em->persist($entity);
            }
            $this->em->flush();
            $this->cache->invalidate();
            return $method === 'DELETE' ? null : $this->mapper->map($kind, $this->db->fetchAssociative('SELECT * FROM '.$kind.' WHERE id=?', [$entity->id]));
        });
    }
    public function activeCourse(int $id): Course
    {
        $c = $this->em->find(Course::class, $id);
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
