<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\{ApiResource, ApiProperty, Get, GetCollection};
use App\Api\State\{ReadProvider, WriteProcessor};

#[ApiResource(shortName: 'Submission', operations: [
    new GetCollection(uriTemplate: '/submissions'),
    new Get(uriTemplate: '/submissions/{id}')
], provider: ReadProvider::class, processor: WriteProcessor::class, extraProperties: ['kind' => 'submissions'])]
final class SubmissionResource
{
    #[ApiProperty(identifier: true, writable: false)]
    public ?int $id = null;
    public int $assignmentId = 0;
    public int $userId = 0;
    public string $originalFilename = '';
    public string $contentType = '';
    public int $sizeBytes = 0;
    public string $sha256 = '';
    public string $submittedAt = '';
}
