<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add photo status constraints, generation tracking, and unique main photo index.';
    }

    public function up(Schema $schema): void
    {
        // Add new columns
        $this->addSql('ALTER TABLE place_photos ADD processing_generation INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE place_photos ADD failure_code VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE place_photos ADD processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // Backfill status values to uppercase
        $this->addSql("UPDATE place_photos SET status = 'QUEUED' WHERE status IN ('queued', 'processing')");
        $this->addSql("UPDATE place_photos SET status = 'COMPLETED' WHERE status = 'completed'");
        $this->addSql("UPDATE place_photos SET status = 'FAILED' WHERE status = 'failed'");
        $this->addSql("UPDATE place_photos SET status = 'DELETING' WHERE status = 'deleting'");

        // Fallback for any invalid status
        $this->addSql("UPDATE place_photos SET status = 'QUEUED' WHERE status NOT IN ('QUEUED', 'PROCESSING', 'COMPLETED', 'FAILED', 'DELETING')");

        // Add CHECK constraints
        $this->addSql("ALTER TABLE place_photos ADD CONSTRAINT check_place_photos_status CHECK (status IN ('QUEUED', 'PROCESSING', 'COMPLETED', 'FAILED', 'DELETING'))");
        $this->addSql('ALTER TABLE place_photos ADD CONSTRAINT check_place_photos_display_order CHECK (display_order >= 0)');
        $this->addSql('ALTER TABLE place_photos ADD CONSTRAINT check_place_photos_processing_generation CHECK (processing_generation >= 1)');

        // Partial unique index: only one is_main = true per place
        $this->addSql('CREATE UNIQUE INDEX uidx_place_photos_is_main ON place_photos (place_id) WHERE is_main = true');

        // Required indexes for status and processing queue
        $this->addSql('CREATE INDEX idx_place_photos_status ON place_photos (status)');
        $this->addSql("CREATE INDEX idx_place_photos_queue ON place_photos (status, created_at) WHERE status = 'QUEUED'");

        $this->addSql('COMMENT ON COLUMN place_photos.processed_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uidx_place_photos_is_main');
        $this->addSql('DROP INDEX IF EXISTS idx_place_photos_status');
        $this->addSql('DROP INDEX IF EXISTS idx_place_photos_queue');
        
        $this->addSql('ALTER TABLE place_photos DROP CONSTRAINT IF EXISTS check_place_photos_status');
        $this->addSql('ALTER TABLE place_photos DROP CONSTRAINT IF EXISTS check_place_photos_display_order');
        $this->addSql('ALTER TABLE place_photos DROP CONSTRAINT IF EXISTS check_place_photos_processing_generation');

        $this->addSql('ALTER TABLE place_photos DROP COLUMN IF EXISTS processing_generation');
        $this->addSql('ALTER TABLE place_photos DROP COLUMN IF EXISTS failure_code');
        $this->addSql('ALTER TABLE place_photos DROP COLUMN IF EXISTS processed_at');
    }
}
