<?php

declare(strict_types=1);

namespace App\Storage;

use App\Api\State\Access;
use App\Entity\{Assignment,Submission};
use App\Repository\{AssignmentRepository, CourseRepository, UserRepository, SubmissionRepository};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,ConflictHttpException,NotFoundHttpException,ServiceUnavailableHttpException};
use Psr\Log\LoggerInterface;

final readonly class SubmissionService
{
    public const MAX_BYTES = 10000000;
    public function __construct(private S3Storage $storage, private AssignmentRepository $assignments, private CourseRepository $courses, private UserRepository $users, private SubmissionRepository $submissions, private Access $access, private LoggerInterface $logger)
    {
    }
    public function upload(int $assignmentId, UploadedFile $file): Submission
    {
        $user = $this->access->user();
        $assignment = $this->check($assignmentId);
        $this->access->enrolled($assignment->course->id, false);
        clearstatcache(true, $file->getPathname());
        if (!$file->isValid() || $file->getSize() > self::MAX_BYTES) {
            throw new BadRequestHttpException('Valid single file at most 10,000,000 bytes required');
        }
        $submission = new Submission();
        $submission->assignment = $assignment;
        $submission->user = $user;
        $submission->objectKey = 'submissions/'.bin2hex(random_bytes(24));
        $name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
        $submission->originalFilename = preg_replace('/[\x00-\x1f\x7f]/', '_', $name) ?: 'submission';
        $submission->contentType = 'application/octet-stream';
        $submission->sizeBytes = $file->getSize();
        $submission->sha256 = hash_file('sha256', $file->getPathname());
        $body = fopen($file->getPathname(), 'rb');
        try {
            $this->storage->put($submission->objectKey, $body, $submission->contentType);
        } catch (\Throwable) {
            throw new ServiceUnavailableHttpException(2, 'Submission storage unavailable');
        } finally {
            fclose($body);
        }
        return $submission;
    }
    public function save(Submission $submission): void
    {
        $this->users->lock($submission->user->id);
        $this->courses->lock($submission->assignment->course->id);
        $this->assignments->lock($submission->assignment->id);
        $this->check($submission->assignment->id);
        $this->access->enrolled($submission->assignment->course->id, false);
        if ($this->submissions->findOneBy(['assignment' => $submission->assignment, 'user' => $submission->user])) {
            throw new ConflictHttpException('Only one submission permitted');
        }
        $this->submissions->save($submission);
    }
    public function discard(Submission $submission): void
    {
        try {
            $this->storage->delete($submission->objectKey);
        } catch (\Throwable) {
            $this->logger->warning('submission.orphan', ['object_key' => $submission->objectKey]);
        }
    }
    private function check(int $id): Assignment
    {
        $assignment = $this->assignments->active($id) ?? throw new NotFoundHttpException();
        if ($assignment->deadline <= new \DateTimeImmutable()) {
            throw new ConflictHttpException('Assignment deadline passed');
        }
        return $assignment;
    }
}
