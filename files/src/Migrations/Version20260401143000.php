<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add retention days setting for Bot Guard logs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_settings ADD retention_days INT NOT NULL DEFAULT 60');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_settings DROP retention_days');
    }
}

