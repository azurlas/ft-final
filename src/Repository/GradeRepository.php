<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{Grade};

/** @extends Repository<Grade> */
final readonly class GradeRepository extends Repository
{
    protected const ENTITY = Grade::class;

}
