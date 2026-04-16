<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Bot Guard module tables (rules, settings, logs) with default bot rules.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bot_guard_log (id INT AUTO_INCREMENT NOT NULL, reason VARCHAR(50) NOT NULL, rule_name VARCHAR(255) DEFAULT NULL, rule_pattern VARCHAR(255) DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, method VARCHAR(10) DEFAULT NULL, uri VARCHAR(255) DEFAULT NULL, user_agent VARCHAR(1024) DEFAULT NULL, status_code INT NOT NULL, blocked_at DATETIME NOT NULL, INDEX idx_bot_guard_log_blocked_at (blocked_at), INDEX idx_bot_guard_log_ip (ip), INDEX idx_bot_guard_log_reason (reason), PRIMARY KEY(id)) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_UNICODE_CI` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE bot_guard_rule (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(32) NOT NULL, pattern VARCHAR(255) NOT NULL, active TINYINT(1) NOT NULL, priority INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_UNICODE_CI` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE bot_guard_settings (id INT AUTO_INCREMENT NOT NULL, enabled TINYINT(1) NOT NULL, block_empty_user_agent TINYINT(1) NOT NULL, logging_enabled TINYINT(1) NOT NULL, block_status_code INT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_UNICODE_CI` ENGINE = InnoDB');

        $this->addSql("INSERT INTO bot_guard_settings (enabled, block_empty_user_agent, logging_enabled, block_status_code, updated_at) VALUES (1, 1, 1, 403, NOW())");

        $rules = [
            'Applebot', 'SemrushBot', 'Ahrefs', 'MJ12bot', 'DotBot', 'rogerbot', 'BLEXBot',
            'GPTBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'anthropic', 'Amazonbot', 'PerplexityBot',
            'YouBot', 'Diffbot', 'Claude-User', 'OAI-SearchBot', 'Perplexity-User', 'PetalBot', 'ProRataInc',
            'Terracotta', 'Timpibot', 'Pingdom', 'GTmetrix', 'Lighthouse', 'PageSpeed', 'WebPageTest', 'Anchor',
            'Baidu', 'Baiduspider', 'Sogou', '360Spider', 'Bytespider', 'Barkrowler', 'DataForSeoBot', 'SerpstatBot',
            'MegaIndex', 'MauiBot', 'Cocolyzebot', 'keys-so-bot', 'zgrab', 'crawler', 'CCBot', 'DuckAssistBot',
            'FacebookBot', 'Manus', 'Meta-ExternalAgent', 'Meta-ExternalFetcher', 'MistralAI-User', 'Novellum',
            'facebookexternalhit', 'meta-externalagent', 'Twitterbot', 'LinkedInBot', 'WhatsApp', 'TelegramBot',
            'bingbot', 'ZoomBot', 'qwantify', 'SEOkicks', 'ImagesiftBot', 'TinEye', 'Webzio', 'AwarioBot',
            'Omgili', 'OpenAI',
        ];

        foreach ($rules as $priority => $rule) {
            $this->addSql(sprintf(
                "INSERT INTO bot_guard_rule (name, type, pattern, active, priority, created_at, updated_at) VALUES ('%s', 'user_agent_contains', '%s', 1, %d, NOW(), NOW())",
                str_replace("'", "''", $rule),
                str_replace("'", "''", $rule),
                $priority + 10
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bot_guard_log');
        $this->addSql('DROP TABLE bot_guard_rule');
        $this->addSql('DROP TABLE bot_guard_settings');
    }
}

