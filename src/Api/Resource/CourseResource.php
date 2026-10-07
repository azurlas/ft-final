<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\{ApiResource, ApiProperty, Get, GetCollection, Post, Patch, Delete};
use App\Api\State\{ReadProvider, WriteProcessor};

#[ApiResource(shortName: 'Course', operations: [
    new GetCollection(uriTemplate: '/courses'),
    new Get(uriTemplate: '/courses/{id}'),
    new Post(uriTemplate: '/courses', input: CourseInput::class, security: "is_granted('ROLE_ADMIN')", read: false),
    new Patch(uriTemplate: '/courses/{id}', input: CourseInput::class, security: "is_granted('ROLE_ADMIN')"),
    new Delete(uriTemplate: '/courses/{id}', security: "is_granted('ROLE_ADMIN')"),
    new Post(uriTemplate: '/courses/{id}/enroll', input: false, output: EnrollmentResource::class, read: false, deserialize: false, security: "is_granted('ROLE_USER')", extraProperties: ['action' => 'enroll']),
    new Post(uriTemplate: '/courses/{id}/purchase', input: \App\Payment\Dto\PaymentDetailsDto::class, output: PurchaseResource::class, read: false, security: "is_granted('ROLE_USER')", extraProperties: ['action' => 'purchase'])
], provider: ReadProvider::class, processor: WriteProcessor::class, extraProperties: ['kind' => 'courses'])]
final class CourseResource
{
    #[ApiProperty(identifier: true, writable: false)]
    public ?int $id = null;
    public string $name = '';
    public ?int $price = null;
    public string $createdAt = '';
}
