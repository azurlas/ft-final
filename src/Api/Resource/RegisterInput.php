<?php

declare(strict_types=1);

namespace App\Api\Resource;

final class RegisterInput
{
    public string $login = '';
    public string $username = '';
    public string $password = '';
}
