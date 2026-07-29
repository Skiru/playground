<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve bounded license audit details and support the actionable candidate queue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE place_candidate_audit_events
    ADD details JSONB NOT NULL DEFAULT '{}'::jsonb,
    ADD CONSTRAINT chk_candidate_audit_details_object CHECK (jsonb_typeof(details) = 'object'),
    ADD CONSTRAINT chk_candidate_audit_details_size CHECK (octet_length(details::text) <= 8192)
SQL);
        $this->addSql('ALTER TABLE place_candidates DROP CONSTRAINT chk_candidate_license_resolutions_size');
        // Raw identity data is capped at 16 KiB; 128 KiB also covers 32 fingerprints and bounded review metadata.
        $this->addSql('ALTER TABLE place_candidates ADD CONSTRAINT chk_candidate_license_resolutions_size CHECK (octet_length(source_license_resolutions::text) <= 131072)');
        $this->addSql('CREATE INDEX idx_candidate_actionable_queue ON place_candidates ((source_license_review_required OR source_closed_review_required) DESC, updated_at DESC, id ASC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_candidate_actionable_queue');
        $this->addSql('ALTER TABLE place_candidates DROP CONSTRAINT chk_candidate_license_resolutions_size');
        $this->addSql('ALTER TABLE place_candidates ADD CONSTRAINT chk_candidate_license_resolutions_size CHECK (octet_length(source_license_resolutions::text) <= 16384)');
        $this->addSql('ALTER TABLE place_candidate_audit_events DROP CONSTRAINT chk_candidate_audit_details_size, DROP CONSTRAINT chk_candidate_audit_details_object, DROP details');
    }
}
