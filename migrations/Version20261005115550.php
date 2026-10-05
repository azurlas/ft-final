<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Initial MySQL schema with reviewed foreign keys and business bounds. */
final class Version20261005115550 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'University model, integrity constraints, and payment recovery indexes';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assignments (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, name VARCHAR(255) NOT NULL, deadline DATETIME NOT NULL, description LONGTEXT NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, course_id INT NOT NULL, INDEX IDX_308A50DD591CC992 (course_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE courses (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, name VARCHAR(255) NOT NULL, price INT DEFAULT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE enrollments (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, source VARCHAR(16) NOT NULL, user_id INT NOT NULL, course_id INT NOT NULL, purchase_id INT DEFAULT NULL, UNIQUE INDEX uniq_enrollments_0 (user_id, course_id), INDEX IDX_CCD8C132A76ED395 (user_id), INDEX IDX_CCD8C132591CC992 (course_id), INDEX IDX_CCD8C132558FBEB9 (purchase_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE grades (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, grade INT NOT NULL, comment LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, assignment_id INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_grades_0 (assignment_id, user_id), INDEX IDX_3AE36110D19302F8 (assignment_id), INDEX IDX_3AE36110A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mock_receipts (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, provider_reference VARCHAR(100) NOT NULL, amount INT NOT NULL, currency VARCHAR(3) NOT NULL, purchase_id INT NOT NULL, UNIQUE INDEX UNIQ_297EB209773D51A1 (provider_reference), UNIQUE INDEX uniq_mock_receipts_0 (purchase_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE outbox (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, type VARCHAR(32) NOT NULL, aggregate_id INT NOT NULL, payload JSON NOT NULL, available_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, lease_until DATETIME DEFAULT NULL, delivery_count INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payment_attempts (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, attempt_number INT NOT NULL, status VARCHAR(24) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, next_attempt_at DATETIME DEFAULT NULL, provider_reference VARCHAR(100) DEFAULT NULL, error_code VARCHAR(100) DEFAULT NULL, duration_ms INT NOT NULL, purchase_id INT NOT NULL, UNIQUE INDEX uniq_payment_attempts_0 (purchase_id, attempt_number), INDEX IDX_FF01A50C558FBEB9 (purchase_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payment_circuit (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, provider VARCHAR(32) NOT NULL, consecutive_failures INT NOT NULL, opened_until DATETIME DEFAULT NULL, probe_lease_until DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_A6CFD4C92C4739C (provider), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE purchases (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, amount INT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(24) NOT NULL, metadata JSON NOT NULL, idempotency_key VARCHAR(128) NOT NULL, request_fingerprint VARCHAR(64) NOT NULL, updated_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, processing_lease_until DATETIME DEFAULT NULL, user_id INT NOT NULL, course_id INT NOT NULL, UNIQUE INDEX uniq_purchases_0 (user_id, idempotency_key), INDEX IDX_AA6431FEA76ED395 (user_id), INDEX IDX_AA6431FE591CC992 (course_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE submissions (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, object_key VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, content_type VARCHAR(128) NOT NULL, size_bytes INT NOT NULL, sha256 VARCHAR(64) NOT NULL, submitted_at DATETIME NOT NULL, assignment_id INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_3F6169F74DDB172C (object_key), UNIQUE INDEX uniq_submissions_0 (assignment_id, user_id), INDEX IDX_3F6169F7D19302F8 (assignment_id), INDEX IDX_3F6169F7A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, login VARCHAR(64) NOT NULL, username VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL, is_admin TINYINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_1483A5E9AA08CB10 (login), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE courses ADD CONSTRAINT ck_course_price CHECK (price IS NULL OR price >= 0)');
        $this->addSql('ALTER TABLE grades ADD CONSTRAINT ck_grade_range CHECK (grade >= 0 AND grade <= 100)');
        $this->addSql('ALTER TABLE submissions ADD CONSTRAINT ck_submission_size CHECK (size_bytes >= 0 AND size_bytes <= 10000000)');
        $this->addSql('ALTER TABLE payment_attempts ADD CONSTRAINT ck_attempt_range CHECK (attempt_number >= 1 AND attempt_number <= 3)');
        $this->addSql('ALTER TABLE purchases ADD CONSTRAINT ck_purchase_amount CHECK (amount > 0)');
        $this->addSql('ALTER TABLE purchases ADD CONSTRAINT ck_purchase_metadata CHECK (JSON_TYPE(metadata) = \'ARRAY\')');
        $this->addSql('CREATE INDEX idx_purchase_active ON purchases (user_id, course_id, status)');
        $this->addSql('CREATE INDEX idx_outbox_due ON outbox (published_at, available_at, lease_until)');
        $this->addSql('CREATE INDEX idx_outbox_aggregate ON outbox (aggregate_id)');
        $this->addSql('CREATE INDEX idx_attempt_due ON payment_attempts (next_attempt_at, status)');
        $this->addSql('ALTER TABLE assignments ADD CONSTRAINT FK_308A50DD591CC992 FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE enrollments ADD CONSTRAINT FK_CCD8C132A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE enrollments ADD CONSTRAINT FK_CCD8C132591CC992 FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE enrollments ADD CONSTRAINT FK_CCD8C132558FBEB9 FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE grades ADD CONSTRAINT FK_3AE36110D19302F8 FOREIGN KEY (assignment_id) REFERENCES assignments (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE grades ADD CONSTRAINT FK_3AE36110A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE mock_receipts ADD CONSTRAINT FK_297EB209558FBEB9 FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE payment_attempts ADD CONSTRAINT FK_FF01A50C558FBEB9 FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE purchases ADD CONSTRAINT FK_AA6431FEA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE purchases ADD CONSTRAINT FK_AA6431FE591CC992 FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE submissions ADD CONSTRAINT FK_3F6169F7D19302F8 FOREIGN KEY (assignment_id) REFERENCES assignments (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE submissions ADD CONSTRAINT FK_3F6169F7A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignments DROP FOREIGN KEY FK_308A50DD591CC992');
        $this->addSql('ALTER TABLE enrollments DROP FOREIGN KEY FK_CCD8C132A76ED395');
        $this->addSql('ALTER TABLE enrollments DROP FOREIGN KEY FK_CCD8C132591CC992');
        $this->addSql('ALTER TABLE enrollments DROP FOREIGN KEY FK_CCD8C132558FBEB9');
        $this->addSql('ALTER TABLE grades DROP FOREIGN KEY FK_3AE36110D19302F8');
        $this->addSql('ALTER TABLE grades DROP FOREIGN KEY FK_3AE36110A76ED395');
        $this->addSql('ALTER TABLE mock_receipts DROP FOREIGN KEY FK_297EB209558FBEB9');
        $this->addSql('ALTER TABLE payment_attempts DROP FOREIGN KEY FK_FF01A50C558FBEB9');
        $this->addSql('ALTER TABLE purchases DROP FOREIGN KEY FK_AA6431FEA76ED395');
        $this->addSql('ALTER TABLE purchases DROP FOREIGN KEY FK_AA6431FE591CC992');
        $this->addSql('ALTER TABLE submissions DROP FOREIGN KEY FK_3F6169F7D19302F8');
        $this->addSql('ALTER TABLE submissions DROP FOREIGN KEY FK_3F6169F7A76ED395');
        $this->addSql('DROP TABLE assignments');
        $this->addSql('DROP TABLE courses');
        $this->addSql('DROP TABLE enrollments');
        $this->addSql('DROP TABLE grades');
        $this->addSql('DROP TABLE mock_receipts');
        $this->addSql('DROP TABLE outbox');
        $this->addSql('DROP TABLE payment_attempts');
        $this->addSql('DROP TABLE payment_circuit');
        $this->addSql('DROP TABLE purchases');
        $this->addSql('DROP TABLE submissions');
        $this->addSql('DROP TABLE users');
    }
}
