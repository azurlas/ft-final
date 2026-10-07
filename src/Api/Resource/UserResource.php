<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\{ApiResource, ApiProperty, Get, Post};
use App\Api\State\{ReadProvider, WriteProcessor};

#[ApiResource(shortName: 'User', operations: [
    new Get(uriTemplate: '/me', uriVariables: [], extraProperties: ['action' => 'me']),
    new Post(uriTemplate: '/register', input: RegisterInput::class, read: false, extraProperties: ['action' => 'register'])
], provider: ReadProvider::class, processor: WriteProcessor::class, extraProperties: ['kind' => 'users'])]
final class UserResource
{
    #[ApiProperty(identifier: true, writable: false)]
    public ?int $id = null;
    public string $login = '';
    public string $username = '';
    public bool $isAdmin = false;
}
