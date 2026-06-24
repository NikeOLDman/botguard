<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bot Guard phase 1: strict cookie rules, path rate limit, trusted referrer domains, JS challenge delay.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_settings ADD trusted_referrer_domains LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE bot_guard_settings ADD path_rate_limit_enabled TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql("ALTER TABLE bot_guard_settings ADD path_rate_limit_uri_pattern VARCHAR(255) NOT NULL DEFAULT '/filtered'");
        $this->addSql('ALTER TABLE bot_guard_settings ADD path_rate_limit_max_requests INT NOT NULL DEFAULT 30');
        $this->addSql('ALTER TABLE bot_guard_settings ADD path_rate_limit_window_seconds INT NOT NULL DEFAULT 60');
        $this->addSql('ALTER TABLE bot_guard_settings ADD js_challenge_min_delay_ms INT NOT NULL DEFAULT 1200');
        $this->addSql("UPDATE bot_guard_rule SET type = 'cookie_strict' WHERE type = 'cookie_required' AND pattern = '/filtered'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_settings DROP trusted_referrer_domains');
        $this->addSql('ALTER TABLE bot_guard_settings DROP path_rate_limit_enabled');
        $this->addSql('ALTER TABLE bot_guard_settings DROP path_rate_limit_uri_pattern');
        $this->addSql('ALTER TABLE bot_guard_settings DROP path_rate_limit_max_requests');
        $this->addSql('ALTER TABLE bot_guard_settings DROP path_rate_limit_window_seconds');
        $this->addSql('ALTER TABLE bot_guard_settings DROP js_challenge_min_delay_ms');
    }
}
