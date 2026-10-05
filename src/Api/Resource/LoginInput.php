<?php

declare(strict_types=1);

namespace App\Api\Resource;

final class LoginInput
{
    public string $login = '';
    public string $password = '';
}
