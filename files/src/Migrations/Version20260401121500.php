<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional URI scope for bot guard rules.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_rule ADD uri_pattern VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_rule DROP uri_pattern');
    }
}

