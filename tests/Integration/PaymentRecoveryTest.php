<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{User, CoursePurchase, PaymentAttempt};
use App\Payment\{MockScenarios, MockPaymentService, HttpPaymentService, PurchaseHandler, PaymentContext, CircuitBreaker, ClaimPurchaseHandler, SettlePurchaseHandler};
use App\Payment\Message\ProcessPurchase;
use App\Persistence\Transaction;
use App\Repository\{UserRepository, CourseRepository, CoursePurchaseRepository, PaymentAttemptRepository, CourseEnrollmentRepository, OutboxMessageRepository, PaymentCircuitStateRepository, MockPaymentReceiptRepository};
use App\RuntimeProfile;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\{MockHttpClient,Response\MockResponse};

final class PaymentRecoveryTest extends KernelTestCase
{
    private function repository(string $class): object
    {
        return self::getContainer()->get($class);
    }
    private function handler(MockHttpClient $client): PurchaseHandler
    {
        $profile = new RuntimeProfile('resilient', 'USD', 'worker');
        $transaction = $this->repository(Transaction::class);
        $users = $this->repository(UserRepository::class);
        $courses = $this->repository(CourseRepository::class);
        $purchases = $this->repository(CoursePurchaseRepository::class);
        $attempts = $this->repository(PaymentAttemptRepository::class);
        $circuit = new CircuitBreaker($this->repository(PaymentCircuitStateRepository::class), $profile);
        return new PurchaseHandler(
            new ClaimPurchaseHandler($transaction, $users, $courses, $purchases, $attempts, $circuit, $profile),
            new SettlePurchaseHandler($transaction, $users, $courses, $purchases, $attempts, $this->repository(CourseEnrollmentRepository::class), $this->repository(OutboxMessageRepository::class), $circuit, $profile),
            new HttpPaymentService($client, 'http://mock', 'token'),
            new MockScenarios(),
            new NullLogger(),
        );
    }
    private function purchase(string $fixture): CoursePurchase
    {
        return $this->repository(Transaction::class)->run(function () use ($fixture) {
            $user = new User();
            $user->login = 'test-'.bin2hex(random_bytes(8));
            $user->username = 'Test';
            $user->passwordHash = 'test';
            $this->repository(UserRepository::class)->save($user);
            $purchase = new CoursePurchase();
            $purchase->user = $user;
            $purchase->course = $this->repository(CourseRepository::class)->findOneBy(['name' => 'Paid Computing']);
            $purchase->amount = 5000;
            $purchase->idempotencyKey = 'test-key';
            $purchase->requestFingerprint = str_repeat('a', 64);
            $purchase->metadata = [['fixture' => $fixture, 'beneficiaryName' => 'Test']];
            $this->repository(CoursePurchaseRepository::class)->save($purchase);
            return $purchase;
        });
    }
    private function clearCircuitAndDelay(CoursePurchase $purchase): void
    {
        $this->repository(Transaction::class)->run(function () use ($purchase) {
            foreach ($this->repository(PaymentAttemptRepository::class)->findBy(['purchase' => $purchase]) as $attempt) {
                $attempt->nextAttemptAt = null;
            }
            $circuit = $this->repository(PaymentCircuitStateRepository::class)->locked();
            $circuit->openedUntil = $circuit->probeLeaseUntil = null;
            $circuit->consecutiveFailures = 0;
        });
    }
    public function testRetryLimitsIdempotentReceiptAndAbandonedAttempt(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->beginTransaction();
        try {
            $scenarios = new MockScenarios();
            $mock = self::getContainer()->get(MockPaymentService::class);
            $pay = fn ($details, $context) => $this->repository(Transaction::class)->run(fn () => $mock->pay($details, $context));
            $client = new MockHttpClient(function ($method, $url, $options) use ($pay, $scenarios) {
                $json = json_decode($options['body'], true);
                $context = $json['context'];
                $details = new \App\Payment\Dto\PaymentDetailsDto();
                foreach ($json['details'] as $key => $value) {
                    $details->$key = $value;
                }
                return new MockResponse(json_encode($pay($details, new PaymentContext($context['purchaseId'], $context['attemptNumber'], $context['amount'], $context['currency']))));
            });
            $handler = $this->handler($client);
            foreach (['success' => 1,'retry_once' => 2,'retry_twice' => 3,'failure' => 3,'decline' => 1] as $fixture => $count) {
                $purchase = $this->purchase($fixture);
                if ($fixture === 'success') {
                    $context = new PaymentContext($purchase->id, 1, 5000, 'USD');
                    $first = $pay($scenarios->dto($fixture, 'Test'), $context);
                    $this->repository(Transaction::class)->run(function () use ($purchase) {
                        $attempt = new PaymentAttempt();
                        $attempt->purchase = $purchase;
                        $this->repository(PaymentAttemptRepository::class)->save($attempt);
                    });
                    self::assertSame($first->reference, $pay($scenarios->dto($fixture, 'Test'), $context)->reference);
                }
                for ($number = 1; $number <= $count; ++$number) {
                    $this->clearCircuitAndDelay($purchase);
                    $handler(new ProcessPurchase($purchase->id));
                }
                $expected = in_array($fixture, ['success','retry_once','retry_twice'], true) ? 'succeeded' : ($fixture === 'decline' ? 'declined' : 'failed');
                self::assertSame($expected, $purchase->status);
                self::assertCount($count, $this->repository(PaymentAttemptRepository::class)->findBy(['purchase' => $purchase]));
                $handler(new ProcessPurchase($purchase->id));
                self::assertCount($count, $this->repository(PaymentAttemptRepository::class)->findBy(['purchase' => $purchase]));
                self::assertCount($expected === 'succeeded' ? 1 : 0, $this->repository(CourseEnrollmentRepository::class)->findBy(['purchase' => $purchase]));
                self::assertCount($expected === 'succeeded' ? 1 : 0, $this->repository(MockPaymentReceiptRepository::class)->findBy(['purchase' => $purchase]));
            }
        } finally {
            $em->getConnection()->rollBack();
        }
    }
    public function testExpiredLeaseDuplicateFailureDoesNotScheduleTwoRetries(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->beginTransaction();
        try {
            $purchase = $this->purchase('failure');
            $this->clearCircuitAndDelay($purchase);
            $handler = null;
            $injected = false;
            $client = new MockHttpClient(function () use ($purchase, &$handler, &$injected) {
                if (!$injected) {
                    $injected = true;
                    $this->repository(Transaction::class)->run(fn () => $purchase->processingLeaseUntil = new \DateTimeImmutable('-1 second'));
                    $handler(new ProcessPurchase($purchase->id));
                }
                return new MockResponse(json_encode(['status' => 'transient','error' => 'provider_unavailable']));
            });
            $handler = $this->handler($client);
            $handler(new ProcessPurchase($purchase->id));
            self::assertTrue($injected);
            self::assertSame('retrying', $purchase->status);
            self::assertCount(1, $this->repository(PaymentAttemptRepository::class)->findBy(['purchase' => $purchase]));
            self::assertCount(1, $this->repository(OutboxMessageRepository::class)->findBy(['purchase' => $purchase]));
            self::assertSame(1, $this->repository(PaymentCircuitStateRepository::class)->findOneBy(['provider' => 'mock'])->consecutiveFailures);
        } finally {
            $em->getConnection()->rollBack();
        }
    }
}
