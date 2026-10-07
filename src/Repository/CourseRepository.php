<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{Course};

/** @extends Repository<Course> */
final readonly class CourseRepository extends Repository
{
    protected const ENTITY = Course::class;

}
