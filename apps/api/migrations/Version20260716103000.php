<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optimistic versioning to the authoritative Place aggregate write model.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE places ADD version INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE places ALTER COLUMN version DROP DEFAULT');
        $this->addSql('ALTER TABLE places ADD CONSTRAINT chk_places_version CHECK (version > 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE places DROP CONSTRAINT chk_places_version');
        $this->addSql('ALTER TABLE places DROP version');
    }
}
