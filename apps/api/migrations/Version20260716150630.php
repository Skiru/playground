<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716150630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist explicit opening hours mode for every place.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE places ADD opening_hours_mode VARCHAR(20) NOT NULL DEFAULT 'unknown'");
        $this->addSql("UPDATE places p SET opening_hours_mode = 'scheduled' WHERE EXISTS (SELECT 1 FROM weekly_opening_intervals w WHERE w.place_id = p.id)");
        $this->addSql('ALTER TABLE places ALTER COLUMN opening_hours_mode DROP DEFAULT');
        $this->addSql("ALTER TABLE places ADD CONSTRAINT chk_places_opening_hours_mode CHECK (opening_hours_mode IN ('unknown', 'scheduled', 'always_open'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE places DROP CONSTRAINT chk_places_opening_hours_mode');
        $this->addSql('ALTER TABLE places DROP opening_hours_mode');
    }
}
