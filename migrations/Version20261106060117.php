<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shared login-throttling state (Symfony DoctrineDbalAdapter table).
 */
final class Version20261106060117 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the cache_items table for login throttling';
    }

    public function isTransactional(): bool
    {
        return false; // MySQL DDL commits implicitly.
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cache_items (item_id VARBINARY(255) NOT NULL, item_data MEDIUMBLOB NOT NULL, item_lifetime INT UNSIGNED DEFAULT NULL, item_time INT UNSIGNED NOT NULL, PRIMARY KEY (item_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cache_items');
    }
}
