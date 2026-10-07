<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Course, CoursePurchase};
use App\Persistence\Transaction;
use App\Repository\{CourseRepository, UserRepository, CoursePurchaseRepository, OutboxMessageRepository};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepositoryPersistenceTest extends KernelTestCase
{
    public function testRepositoryStagesWithoutFlushingAndPurchaseGraphUsesOneFlush(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->beginTransaction();
        $listener = new class () {
            public int $flushes = 0;
            public function onFlush(): void
            {
                ++$this->flushes;
            }
        };
        $em->getEventManager()->addEventListener(['onFlush'], $listener);
        try {
            $courses = self::getContainer()->get(CourseRepository::class);
            $purchases = self::getContainer()->get(CoursePurchaseRepository::class);
            $outbox = self::getContainer()->get(OutboxMessageRepository::class);
            $course = new Course();
            $course->name = 'staged-'.bin2hex(random_bytes(8));
            $course->price = 100;
            $courses->save($course);
            self::assertNull($course->id);
            self::assertNull($courses->findOneBy(['name' => $course->name]));
            $purchase = new CoursePurchase();
            $purchase->user = self::getContainer()->get(UserRepository::class)->findOneBy(['login' => 'student']);
            $purchase->course = $course;
            $purchase->amount = 100;
            $purchase->idempotencyKey = bin2hex(random_bytes(12));
            $purchase->requestFingerprint = str_repeat('f', 64);
            $purchases->save($purchase);
            $message = $outbox->schedule($purchase);
            self::assertNull($purchase->id);
            self::assertNull($message->id);
            self::assertSame(0, $listener->flushes);
            self::getContainer()->get(Transaction::class)->run(static fn () => null);
            self::assertSame(1, $listener->flushes);
            self::assertNotNull($course->id);
            self::assertNotNull($purchase->id);
            self::assertNotNull($message->id);
            $id = $message->id;
            $purchaseId = $purchase->id;
            $em->clear();
            self::assertSame($purchaseId, $outbox->find($id)->purchase->id);
        } finally {
            $em->getEventManager()->removeEventListener(['onFlush'], $listener);
            $em->getConnection()->rollBack();
        }
    }
    public function testFailedUnitOfWorkDiscardsStagedEntitiesAndCanBeRetried(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->beginTransaction();
        try {
            $courses = self::getContainer()->get(CourseRepository::class);
            $transaction = self::getContainer()->get(Transaction::class);
            $name = 'rollback-'.bin2hex(random_bytes(8));
            try {
                $transaction->run(function () use ($courses, $name) {
                    $course = new Course();
                    $course->name = $name;
                    $courses->save($course);
                    throw new \RuntimeException('Abort before final flush');
                });
                self::fail('Expected rollback');
            } catch (\RuntimeException $error) {
                self::assertSame('Abort before final flush', $error->getMessage());
            }
            self::assertNull($courses->findOneBy(['name' => $name]));
            $course = $transaction->run(function () use ($courses, $name) {
                $course = new Course();
                $course->name = $name;
                $courses->save($course);
                return $course;
            });
            self::assertNotNull($course->id);
        } finally {
            $em->getConnection()->rollBack();
        }
    }
}
