<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add metadata JSON column to moderation_actions for lock/pin audit flags';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE moderation_actions ADD metadata JSONB NOT NULL DEFAULT '{}'::jsonb");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE moderation_actions DROP COLUMN IF EXISTS metadata');
    }
}
