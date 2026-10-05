<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\{ApiResource, ApiProperty, Get, GetCollection, Post, Patch, Delete, Link};
use App\Api\State\{ReadProvider, WriteProcessor};

#[ApiResource(shortName: 'Assignment', operations: [
    new GetCollection(uriTemplate: '/courses/{courseId}/assignments', uriVariables: ['courseId' => new Link(fromClass: CourseResource::class, identifiers: ['id'])]),
    new Get(uriTemplate: '/assignments/{id}'),
    new Post(uriTemplate: '/assignments', input: AssignmentInput::class, security: "is_granted('ROLE_ADMIN')", read: false),
    new Patch(uriTemplate: '/assignments/{id}', input: AssignmentInput::class, security: "is_granted('ROLE_ADMIN')"),
    new Delete(uriTemplate: '/assignments/{id}', security: "is_granted('ROLE_ADMIN')")
], provider: ReadProvider::class, processor: WriteProcessor::class, extraProperties: ['kind' => 'assignments'])]
final class AssignmentResource
{
    #[ApiProperty(identifier: true, writable: false)]
    public ?int $id = null;
    public string $name = '';
    public int $courseId = 0;
    public string $createdAt = '';
    public string $deadline = '';
    public string $description = '';
}
