<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\{ApiResource, ApiProperty, Get, GetCollection, Post, Patch, Delete};
use App\Api\State\{ReadProvider, WriteProcessor};

#[ApiResource(shortName: 'Grade', operations: [
    new GetCollection(uriTemplate: '/grades'),
    new Get(uriTemplate: '/grades/{id}'),
    new Post(uriTemplate: '/grades', input: GradeInput::class, security: "is_granted('ROLE_ADMIN')", read: false),
    new Patch(uriTemplate: '/grades/{id}', input: GradeInput::class, security: "is_granted('ROLE_ADMIN')"),
    new Delete(uriTemplate: '/grades/{id}', security: "is_granted('ROLE_ADMIN')")
], provider: ReadProvider::class, processor: WriteProcessor::class, extraProperties: ['kind' => 'grades'])]
final class GradeResource
{
    #[ApiProperty(identifier: true, writable: false)]
    public ?int $id = null;
    public int $assignmentId = 0;
    public int $userId = 0;
    public int $grade = 0;
    public ?string $comment = null;
    public string $updatedAt = '';
}
