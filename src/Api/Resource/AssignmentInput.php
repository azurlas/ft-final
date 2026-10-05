<?php

declare(strict_types=1);

namespace App\Api\Resource;

final class AssignmentInput
{
    public ?string $name = null;
    public ?int $courseId = null;
    public ?string $deadline = null;
    public ?string $description = null;
}
