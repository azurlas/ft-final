<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\{ApiResource, ApiProperty, Get, GetCollection};
use App\Api\State\{ReadProvider, WriteProcessor};

#[ApiResource(shortName: 'Purchase', operations: [
    new GetCollection(uriTemplate: '/purchases'),
    new Get(uriTemplate: '/purchases/{id}')
], provider: ReadProvider::class, processor: WriteProcessor::class, extraProperties: ['kind' => 'purchases'])]
final class PurchaseResource
{
    #[ApiProperty(identifier: true, writable: false)]
    public ?int $id = null;
    public int $userId = 0;
    public int $courseId = 0;
    public int $amount = 0;
    public string $currency = '';
    public string $status = '';
    public array $metadata = [];
    public array $attempts = [];
    public string $createdAt = '';
    public ?string $completedAt = null;
}
