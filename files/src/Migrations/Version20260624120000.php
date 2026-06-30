<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bot Guard form settings: shield logo URL and color theme.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE bot_guard_form_settings ADD shield_logo_url VARCHAR(255) DEFAULT NULL, ADD shield_theme VARCHAR(16) NOT NULL DEFAULT 'blue'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_form_settings DROP shield_logo_url, DROP shield_theme');
    }
}
