<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260506120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bot Guard: rate limit, auto under attack, reduce logging under attack settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_settings ADD rate_limit_enabled TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE bot_guard_settings ADD rate_limit_max_requests INT NOT NULL DEFAULT 60');
        $this->addSql('ALTER TABLE bot_guard_settings ADD rate_limit_window_seconds INT NOT NULL DEFAULT 60');
        $this->addSql('ALTER TABLE bot_guard_settings ADD reduce_logging_under_attack TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE bot_guard_settings ADD auto_under_attack_enabled TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE bot_guard_settings ADD auto_under_attack_cpu_percent INT NOT NULL DEFAULT 95');
        $this->addSql('ALTER TABLE bot_guard_settings ADD auto_under_attack_mem_percent INT NOT NULL DEFAULT 95');
        $this->addSql('ALTER TABLE bot_guard_settings ADD auto_under_attack_duration_minutes INT NOT NULL DEFAULT 3');
        $this->addSql('ALTER TABLE bot_guard_settings ADD auto_under_attack_release_percent INT NOT NULL DEFAULT 75');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_settings DROP rate_limit_enabled');
        $this->addSql('ALTER TABLE bot_guard_settings DROP rate_limit_max_requests');
        $this->addSql('ALTER TABLE bot_guard_settings DROP rate_limit_window_seconds');
        $this->addSql('ALTER TABLE bot_guard_settings DROP reduce_logging_under_attack');
        $this->addSql('ALTER TABLE bot_guard_settings DROP auto_under_attack_enabled');
        $this->addSql('ALTER TABLE bot_guard_settings DROP auto_under_attack_cpu_percent');
        $this->addSql('ALTER TABLE bot_guard_settings DROP auto_under_attack_mem_percent');
        $this->addSql('ALTER TABLE bot_guard_settings DROP auto_under_attack_duration_minutes');
        $this->addSql('ALTER TABLE bot_guard_settings DROP auto_under_attack_release_percent');
    }
}
