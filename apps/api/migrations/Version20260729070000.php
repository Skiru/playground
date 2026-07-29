<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Separate raw provider provenance from bounded reviewed license resolutions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE place_candidates
    ADD source_license_resolutions JSONB NOT NULL DEFAULT '{}'::jsonb,
    ADD source_license_review_required BOOLEAN NOT NULL DEFAULT FALSE,
    ADD CONSTRAINT chk_candidate_license_resolutions_object CHECK (jsonb_typeof(source_license_resolutions) = 'object'),
    ADD CONSTRAINT chk_candidate_license_resolutions_size CHECK (octet_length(source_license_resolutions::text) <= 16384)
SQL);
        $this->addSql(<<<'SQL'
UPDATE place_candidates
SET source_license_review_required = jsonb_array_length(source_provenance) = 0 OR EXISTS (
    SELECT 1
    FROM jsonb_array_elements(source_provenance) AS provenance_item(value)
    WHERE NULLIF(BTRIM(provenance_item.value->>'license'), '') IS NULL
)
WHERE jsonb_typeof(source_provenance) = 'array'
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE place_candidates DROP CONSTRAINT chk_candidate_license_resolutions_size, DROP CONSTRAINT chk_candidate_license_resolutions_object, DROP source_license_review_required, DROP source_license_resolutions');
    }
}
