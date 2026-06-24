<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260623120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bot Guard: form protection settings (shield for CMS lead forms).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bot_guard_form_settings (id INT AUTO_INCREMENT NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0, protect_checkout TINYINT(1) NOT NULL DEFAULT 0, min_fill_seconds INT NOT NULL DEFAULT 3, min_confirm_delay_ms INT NOT NULL DEFAULT 400, rate_limit_enabled TINYINT(1) NOT NULL DEFAULT 1, rate_limit_max_requests INT NOT NULL DEFAULT 10, rate_limit_window_seconds INT NOT NULL DEFAULT 3600, blocked_names LONGTEXT DEFAULT NULL, blocked_emails LONGTEXT DEFAULT NULL, logging_enabled TINYINT(1) NOT NULL DEFAULT 1, check_honeypot TINYINT(1) NOT NULL DEFAULT 1, updated_at DATETIME DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_UNICODE_CI` ENGINE = InnoDB');
        $this->addSql('INSERT INTO bot_guard_form_settings (enabled, protect_checkout, min_fill_seconds, min_confirm_delay_ms, rate_limit_enabled, rate_limit_max_requests, rate_limit_window_seconds, logging_enabled, check_honeypot, updated_at) VALUES (0, 0, 3, 400, 1, 10, 3600, 1, 1, NOW())');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bot_guard_form_settings');
    }
}
