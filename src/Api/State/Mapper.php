<?php

declare(strict_types=1);

namespace App\Api\State;

use App\Api\Resource\{CourseResource,AssignmentResource,GradeResource,EnrollmentResource,PurchaseResource,SubmissionResource,UserResource};
use App\Entity\CoursePurchase;
use App\Repository\PaymentAttemptRepository;

final readonly class Mapper
{
    private const CLASSES = ['courses' => CourseResource::class,'assignments' => AssignmentResource::class,'grades' => GradeResource::class,'enrollments' => EnrollmentResource::class,'purchases' => PurchaseResource::class,'submissions' => SubmissionResource::class,'users' => UserResource::class];
    public function __construct(private PaymentAttemptRepository $attempts)
    {
    }
    public function map(string $kind, object $entity): object
    {
        $out = new (self::CLASSES[$kind])();
        foreach (get_object_vars($out) as $field => $unused) {
            if ($field === 'attempts') {
                continue;
            }
            if (str_ends_with($field, 'Id')) {
                $relation = substr($field, 0, -2);
                $value = $entity->$relation?->id;
            } elseif (property_exists($entity, $field)) {
                $value = $entity->$field;
            } else {
                continue;
            }
            $out->$field = $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
        }
        if ($entity instanceof CoursePurchase) {
            $out->attempts = array_map(static fn ($a) => [
                'attempt_number' => $a->attemptNumber, 'status' => $a->status,
                'error_code' => $a->errorCode, 'provider_reference' => $a->providerReference,
                'started_at' => $a->startedAt->format('Y-m-d H:i:s'),
                'finished_at' => $a->finishedAt?->format('Y-m-d H:i:s'), 'duration_ms' => $a->durationMs,
            ], $this->attempts->findBy(['purchase' => $entity], ['attemptNumber' => 'ASC']));
        }
        return $out;
    }
    public function cachedCourse(array $values): CourseResource
    {
        $out = new CourseResource();
        foreach ($values as $field => $value) {
            $out->$field = $value;
        }
        return $out;
    }
}
