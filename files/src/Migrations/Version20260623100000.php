<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260623100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bot Guard: soft cookie check for CMS catalog filter pages instead of strict /filtered rule.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_settings ADD catalog_filter_pages_soft_check TINYINT(1) NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_settings DROP catalog_filter_pages_soft_check');
    }
}
