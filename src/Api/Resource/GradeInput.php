<?php

declare(strict_types=1);

namespace App\Api\Resource;

final class GradeInput
{
    public ?int $assignmentId = null;
    public ?int $userId = null;
    public ?int $grade = null;
    public ?string $comment = null;
}
