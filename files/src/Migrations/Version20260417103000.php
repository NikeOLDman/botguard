<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260417103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Bot Guard trust_referrer setting for cookie challenge bypass on external referrer.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_settings ADD trust_referrer TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bot_guard_settings DROP trust_referrer');
    }
}
