<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\{Submission};

/** @extends Repository<Submission> */
final readonly class SubmissionRepository extends Repository
{
    protected const ENTITY = Submission::class;

}
