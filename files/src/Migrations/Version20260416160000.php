<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cookie-check settings for Bot Guard (under attack mode and user-agent whitelist).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_settings ADD under_attack TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE bot_guard_settings ADD cookie_whitelist_user_agents LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_settings DROP under_attack');
        $this->addSql('ALTER TABLE bot_guard_settings DROP cookie_whitelist_user_agents');
    }
}
