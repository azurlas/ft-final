<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{User};

/** @extends Repository<User> */
final readonly class UserRepository extends Repository
{
    protected const ENTITY = User::class;

}
