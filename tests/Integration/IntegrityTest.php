<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IntegrityTest extends KernelTestCase
{
    private Connection $db;
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
    }
    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        } parent::tearDown();
    }
    public function testNegativeCoursePriceIsRejectedByDatabase(): void
    {
        $this->expectException(Exception::class);
        $this->db->executeStatement("INSERT INTO courses (name,price,created_at,updated_at) VALUES ('invalid',-1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    }
    public function testDuplicateEnrollmentIsRejectedByDatabase(): void
    {
        $user = $this->db->fetchOne("SELECT id FROM users WHERE login='student'");
        $course = $this->db->fetchOne("SELECT id FROM courses WHERE name='Free Computing'");
        $this->db->executeStatement("INSERT IGNORE INTO enrollments (user_id,course_id,source,created_at) VALUES (?,?,'free',UTC_TIMESTAMP())", [$user,$course]);
        $this->expectException(Exception::class);
        $this->db->executeStatement("INSERT INTO enrollments (user_id,course_id,source,created_at) VALUES (?,?,'free',UTC_TIMESTAMP())", [$user,$course]);
    }
    public function testDuplicateLoginIsRejectedByDatabase(): void
    {
        $this->expectException(Exception::class);
        $this->db->executeStatement("INSERT INTO users (login,username,password_hash,is_admin,created_at) VALUES ('student','Imposter','x',0,UTC_TIMESTAMP())");
    }
}
