<?php

declare(strict_types=1);

namespace App\Api\Resource;

use ApiPlatform\Metadata\{ApiResource, Post};

#[ApiResource(shortName: 'Authentication', operations: [new Post(uriTemplate: '/login', input: LoginInput::class, read: false, deserialize: false, write: false, output: LoginResource::class)])]
final class LoginResource
{
    public string $token = '';
}
