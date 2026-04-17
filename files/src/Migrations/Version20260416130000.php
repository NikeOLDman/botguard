<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Bot Guard monitoring tables (system metrics and suspicious unblocked events).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bot_guard_system_metric (id INT AUTO_INCREMENT NOT NULL, sampled_at DATETIME NOT NULL, load_1 DOUBLE PRECISION DEFAULT NULL, load_5 DOUBLE PRECISION DEFAULT NULL, load_15 DOUBLE PRECISION DEFAULT NULL, mem_total_mb DOUBLE PRECISION DEFAULT NULL, mem_used_mb DOUBLE PRECISION DEFAULT NULL, mem_used_percent DOUBLE PRECISION DEFAULT NULL, source VARCHAR(64) NOT NULL, INDEX idx_bot_guard_system_metric_sampled_at (sampled_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_UNICODE_CI` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE bot_guard_suspicious_event (id INT AUTO_INCREMENT NOT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(1024) DEFAULT NULL, method VARCHAR(10) DEFAULT NULL, uri VARCHAR(255) DEFAULT NULL, reason VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_bot_guard_suspicious_created_at (created_at), INDEX idx_bot_guard_suspicious_ip (ip), INDEX idx_bot_guard_suspicious_reason (reason), PRIMARY KEY(id)) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_UNICODE_CI` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bot_guard_suspicious_event');
        $this->addSql('DROP TABLE bot_guard_system_metric');
    }
}
