<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Map the transactional outbox to its purchase without an intermediate flush.
 */
final class Version20261007223818 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce the outbox purchase association';
    }

    public function isTransactional(): bool
    {
        return false; // MySQL DDL commits implicitly.
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE outbox ADD CONSTRAINT FK_6AE7D570D0BBCCBE FOREIGN KEY (aggregate_id) REFERENCES purchases (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE outbox DROP FOREIGN KEY FK_6AE7D570D0BBCCBE');
    }
}
