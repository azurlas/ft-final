<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\{ApiResource, ApiProperty, GetCollection};
use App\Api\State\{ReadProvider, WriteProcessor};

#[ApiResource(shortName: 'Enrollment', operations: [
    new GetCollection(uriTemplate: '/enrollments')
], provider: ReadProvider::class, processor: WriteProcessor::class, extraProperties: ['kind' => 'enrollments'])]
final class EnrollmentResource
{
    #[ApiProperty(identifier: true, writable: false)]
    public ?int $id = null;
    public int $userId = 0;
    public int $courseId = 0;
    public string $source = '';
    public ?int $purchaseId = null;
    public string $createdAt = '';
}
