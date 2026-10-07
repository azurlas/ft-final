<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{User, Course, CourseEnrollment};
use App\Repository\{UserRepository, CourseRepository, CourseEnrollmentRepository};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IntegrityTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }
    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }
    public function testNegativeCoursePriceIsRejectedByDatabase(): void
    {
        $course = new Course();
        $course->name = 'invalid';
        $course->price = -1;
        self::getContainer()->get(CourseRepository::class)->save($course);
        $this->expectException(Exception::class);
        $this->em->flush();
    }
    public function testDuplicateEnrollmentIsRejectedByDatabase(): void
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['login' => 'student']);
        $course = self::getContainer()->get(CourseRepository::class)->findOneBy(['name' => 'Free Computing']);
        $repository = self::getContainer()->get(CourseEnrollmentRepository::class);
        if (!$repository->findOneBy(['user' => $user, 'course' => $course])) {
            $first = new CourseEnrollment();
            $first->user = $user;
            $first->course = $course;
            $repository->save($first);
            $this->em->flush();
        }
        $duplicate = new CourseEnrollment();
        $duplicate->user = $user;
        $duplicate->course = $course;
        $repository->save($duplicate);
        $this->expectException(Exception::class);
        $this->em->flush();
    }
    public function testDuplicateLoginIsRejectedByDatabase(): void
    {
        $user = new User();
        $user->login = 'student';
        $user->username = 'Imposter';
        $user->passwordHash = 'x';
        self::getContainer()->get(UserRepository::class)->save($user);
        $this->expectException(Exception::class);
        $this->em->flush();
    }
}
