<?php

declare(strict_types=1);

namespace App\Api\State;

use App\Api\Resource\{CourseResource,AssignmentResource,GradeResource,EnrollmentResource,PurchaseResource,SubmissionResource,UserResource};
use Doctrine\DBAL\Connection;

final class Mapper
{
    private const CLASSES = ['courses' => CourseResource::class,'assignments' => AssignmentResource::class,'grades' => GradeResource::class,'enrollments' => EnrollmentResource::class,'purchases' => PurchaseResource::class,'submissions' => SubmissionResource::class,'users' => UserResource::class];
    public function __construct(private Connection $db)
    {
    }
    public function map(string $kind, array $row): object
    {
        $out = new (self::CLASSES[$kind])();
        foreach (get_object_vars($out) as $field => $value) {
            $column = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
            if (!array_key_exists($column, $row)) {
                continue;
            }
            $v = $row[$column];
            if ($v !== null && is_int($value)) {
                $v = (int)$v;
            }
            if (is_bool($value)) {
                $v = (bool)$v;
            }
            if ($field === 'id') {
                $v = (int)$v;
            }
            if ($field === 'price' || $field === 'purchaseId') {
                $v = $v === null ? null : (int)$v;
            }
            if ($field === 'metadata') {
                $v = json_decode($v, true, 512, JSON_THROW_ON_ERROR);
            }
            if ($v !== null && (str_ends_with($field, 'At') || $field === 'deadline')) {
                $v = (new \DateTimeImmutable($v, new \DateTimeZone('UTC')))->format(DATE_ATOM);
            }
            $out->$field = $v;
        }
        if ($kind === 'purchases') {
            $out->attempts = $this->db->fetchAllAssociative('SELECT attempt_number,status,error_code,provider_reference,started_at,finished_at,duration_ms FROM payment_attempts WHERE purchase_id=? ORDER BY attempt_number', [$row['id']]);
        }
        return $out;
    }
}
