<?php

declare(strict_types=1);

namespace App\Payment;

use App\Api\State\Access;
use App\Entity\{CourseEnrollment, CoursePurchase};
use App\Payment\Dto\PaymentDetailsDto;
use App\Payment\Message\ProcessPurchase;
use App\Repository\{UserRepository, CourseRepository, CourseEnrollmentRepository, CoursePurchaseRepository, OutboxMessageRepository};
use App\RuntimeProfile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,ConflictHttpException,NotFoundHttpException};

final readonly class CheckoutService
{
    public function __construct(private UserRepository $users, private CourseRepository $courses, private CourseEnrollmentRepository $enrollments, private CoursePurchaseRepository $purchases, private OutboxMessageRepository $outbox, private Access $access, private MockScenarios $scenarios, private RuntimeProfile $profile, private MessageBusInterface $bus, private RequestStack $requests)
    {
    }
    public function enroll(int $courseId): CourseEnrollment
    {
        $user = $this->access->user();
        $this->users->lock($user->id);
        $course = $this->courses->lock($courseId);
        if (!$course || $course->deletedAt) {
            throw new NotFoundHttpException();
        }
        if ($course->price > 0) {
            throw new ConflictHttpException('Paid course requires purchase');
        }
        if ($this->purchases->activeForCourse($courseId, $user->id)) {
            throw new ConflictHttpException('Purchase is active');
        }
        $enrollment = $this->enrollments->findOneBy(['user' => $user, 'course' => $course]);
        if (!$enrollment) {
            $enrollment = new CourseEnrollment();
            $enrollment->user = $user;
            $enrollment->course = $course;
            $this->enrollments->save($enrollment);
        }
        return $enrollment;
    }
    public function purchase(int $courseId, PaymentDetailsDto $dto, string $key): CoursePurchase
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $key)) {
            throw new BadRequestHttpException('Idempotency-Key of 8-128 safe characters required');
        }
        $fixture = $this->scenarios->resolve($dto);
        $user = $this->access->user();
        $fingerprint = hash('sha256', json_encode([$courseId, $fixture, $dto->beneficiaryName]));
        $this->users->lock($user->id);
        $existing = $this->purchases->findOneBy(['user' => $user, 'idempotencyKey' => $key]);
        if ($existing) {
            if ($existing->requestFingerprint !== $fingerprint) {
                throw new ConflictHttpException('Idempotency key reused with a different request');
            }
            return $existing;
        }
        $course = $this->courses->lock($courseId);
        if (!$course || $course->deletedAt) {
            throw new NotFoundHttpException();
        }
        if (!$course->price) {
            throw new ConflictHttpException('Free course uses enrollment');
        }
        if ($this->enrollments->findOneBy(['user' => $user, 'course' => $course])) {
            throw new ConflictHttpException('Already enrolled');
        }
        if ($this->purchases->activeForCourse($courseId, $user->id)) {
            throw new ConflictHttpException('Purchase already active');
        }
        $purchase = new CoursePurchase();
        $purchase->user = $user;
        $purchase->course = $course;
        $purchase->amount = $course->price;
        $purchase->currency = $this->profile->currency;
        $purchase->idempotencyKey = $key;
        $purchase->requestFingerprint = $fingerprint;
        $purchase->metadata = [['fixture' => $fixture, 'beneficiaryName' => $dto->beneficiaryName, 'requestId' => $this->requests->getCurrentRequest()?->attributes->get('_request_id', '')]];
        $this->purchases->save($purchase);
        if ($this->profile->resilient()) {
            $this->outbox->schedule($purchase);
        }
        return $purchase;
    }
    public function afterCommit(CoursePurchase $purchase): void
    {
        if (!$this->profile->resilient()) {
            $this->bus->dispatch(new ProcessPurchase($purchase->id, $purchase->metadata[0]['requestId'] ?? ''));
        }
    }
}
