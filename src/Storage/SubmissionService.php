<?php

declare(strict_types=1);

namespace App\Storage;

use App\Api\State\{Access,Mapper};
use App\Entity\{Assignment,Submission};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,ConflictHttpException,NotFoundHttpException,ServiceUnavailableHttpException};
use Psr\Log\LoggerInterface;

final class SubmissionService
{
    public const MAX_BYTES = 10000000;
    public function __construct(private S3Storage $storage, private Connection $db, private EntityManagerInterface $em, private Access $access, private Mapper $mapper, private LoggerInterface $logger)
    {
    }
    public function submit(int $assignmentId, UploadedFile $file): object
    {
        $u = $this->access->user();
        $a = $this->check($assignmentId);
        $this->access->enrolled((int)$a['course_id'], false);
        clearstatcache(true, $file->getPathname());
        if (!$file->isValid() || $file->getSize() > self::MAX_BYTES) {
            throw new BadRequestHttpException('Valid single file at most 10,000,000 bytes required');
        }
        $key = 'submissions/'.bin2hex(random_bytes(24));
        $body = fopen($file->getPathname(), 'rb');
        $name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
        $name = preg_replace('/[\x00-\x1f\x7f]/', '_', $name) ?: 'submission';
        try {
            $this->storage->put($key, $body, 'application/octet-stream');
        } catch (\Throwable) {
            throw new ServiceUnavailableHttpException(2, 'Submission storage unavailable');
        } finally {
            fclose($body);
        }
        try {
            return $this->db->transactional(function () use ($assignmentId, $u, $file, $key, $name, $a) {
                $this->db->fetchOne('SELECT id FROM users WHERE id=? FOR UPDATE', [$u->id]);
                $this->db->fetchOne('SELECT id FROM courses WHERE id=? FOR UPDATE', [$a['course_id']]);
                $this->db->fetchOne('SELECT id FROM assignments WHERE id=? FOR UPDATE', [$assignmentId]);
                $this->check($assignmentId);
                $this->access->enrolled((int)$a['course_id'], false);
                if ($this->db->fetchOne('SELECT id FROM submissions WHERE assignment_id=? AND user_id=?', [$assignmentId,$u->id])) {
                    throw new ConflictHttpException('Only one submission permitted');
                }
                $s = new Submission();
                $s->assignment = $this->em->getReference(Assignment::class, $assignmentId);
                $s->user = $u;
                $s->objectKey = $key;
                $s->originalFilename = $name;
                $s->contentType = 'application/octet-stream';
                $s->sizeBytes = $file->getSize();
                $s->sha256 = hash_file('sha256', $file->getPathname());
                $this->em->persist($s);
                $this->em->flush();
                return $this->mapper->map('submissions', $this->db->fetchAssociative('SELECT * FROM submissions WHERE id=?', [$s->id]));
            });
        } catch (\Throwable $e) {
            try {
                $this->storage->delete($key);
            } catch (\Throwable) {
                $this->logger->warning('submission.orphan', ['object_key' => $key]);
            } throw $e;
        }
    }
    private function check(int $id): array
    {
        $a = $this->db->fetchAssociative('SELECT a.* FROM assignments a JOIN courses c ON c.id=a.course_id WHERE a.id=? AND a.deleted_at IS NULL AND c.deleted_at IS NULL', [$id]);
        if (!$a) {
            throw new NotFoundHttpException();
        }
        if (strtotime($a['deadline'].' UTC') <= time()) {
            throw new ConflictHttpException('Assignment deadline passed');
        } return $a;
    }
}
