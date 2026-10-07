<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Storage\{SubmissionService,S3Storage};
use App\Api\State\Access;
use App\Repository\SubmissionRepository;
use App\Persistence\Transaction;
use App\Api\State\Mapper;
use Symfony\Component\HttpFoundation\{Request,JsonResponse,StreamedResponse,HeaderUtils};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,NotFoundHttpException};
use Symfony\Component\Routing\Attribute\Route;

final readonly class SubmissionController
{
    public function __construct(private SubmissionService $submissions, private S3Storage $storage, private Access $access, private SubmissionRepository $repository, private Transaction $transaction, private Mapper $mapper)
    {
    }
    #[Route('/api/assignments/{id}/submit', methods:['POST'])]
    public function submit(int $id, Request $request): JsonResponse
    {
        $files = $request->files->all();
        if (count($files) !== 1 || !($files['file'] ?? null) instanceof UploadedFile || $request->request->count() !== 0) {
            throw new BadRequestHttpException('Exactly one multipart field named file required');
        }
        $submission = $this->submissions->upload($id, $files['file']);
        try {
            $this->transaction->run(fn () => $this->submissions->save($submission));
        } catch (\Throwable $error) {
            $this->submissions->discard($submission);
            throw $error;
        }
        return new JsonResponse($this->mapper->map('submissions', $submission), 201);
    }
    #[Route('/api/submissions/{id}/download', methods:['GET'])]
    public function download(int $id): StreamedResponse
    {
        $s = $this->repository->find($id);
        if (!$s) {
            throw new NotFoundHttpException();
        } $this->access->owner($s->user->id);
        $body = $this->storage->get($s->objectKey);
        return new StreamedResponse(function () use ($body) {
            while (!$body->eof()) {
                echo $body->read(65536);
            }
        }, 200, ['Content-Type' => 'application/octet-stream','Content-Length' => (string)$s->sizeBytes,'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $s->originalFilename, 'submission'),'X-Content-Type-Options' => 'nosniff','Cache-Control' => 'private, no-store']);
    }
}
