<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Storage\{SubmissionService,S3Storage};
use App\Api\State\Access;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\{Request,JsonResponse,StreamedResponse,HeaderUtils};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,NotFoundHttpException};
use Symfony\Component\Routing\Attribute\Route;

final class SubmissionController
{
    public function __construct(private SubmissionService $submissions, private S3Storage $storage, private Access $access, private Connection $db)
    {
    }
    #[Route('/api/assignments/{id}/submit', methods:['POST'])]
    public function submit(int $id, Request $request): JsonResponse
    {
        $files = $request->files->all();
        if (count($files) !== 1 || !($files['file'] ?? null) instanceof UploadedFile || $request->request->count() !== 0) {
            throw new BadRequestHttpException('Exactly one multipart field named file required');
        }
        return new JsonResponse($this->submissions->submit($id, $files['file']), 201);
    }
    #[Route('/api/submissions/{id}/download', methods:['GET'])]
    public function download(int $id): StreamedResponse
    {
        $s = $this->db->fetchAssociative('SELECT * FROM submissions WHERE id=?', [$id]);
        if (!$s) {
            throw new NotFoundHttpException();
        } $this->access->owner((int)$s['user_id']);
        $body = $this->storage->get($s['object_key']);
        return new StreamedResponse(function () use ($body) {
            while (!$body->eof()) {
                echo $body->read(65536);
            }
        }, 200, ['Content-Type' => 'application/octet-stream','Content-Length' => (string)$s['size_bytes'],'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $s['original_filename'], 'submission'),'X-Content-Type-Options' => 'nosniff','Cache-Control' => 'private, no-store']);
    }
}
