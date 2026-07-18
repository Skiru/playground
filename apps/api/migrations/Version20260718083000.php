<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718083000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create place_photos table for C4 Places Media & Gallery module.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE place_photos (
            id UUID NOT NULL PRIMARY KEY,
            place_id UUID NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            status VARCHAR(40) NOT NULL,
            is_main BOOLEAN NOT NULL DEFAULT false,
            display_order INT NOT NULL DEFAULT 0,
            alt_text VARCHAR(255) DEFAULT NULL,
            caption VARCHAR(500) DEFAULT NULL,
            variants JSON DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            CONSTRAINT fk_place_photos_place FOREIGN KEY (place_id) REFERENCES places (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE INDEX idx_place_photos_place ON place_photos (place_id)');
        $this->addSql('CREATE INDEX idx_place_photos_order ON place_photos (place_id, display_order)');
        $this->addSql('COMMENT ON COLUMN place_photos.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN place_photos.updated_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS place_photos CASCADE');
    }
}
