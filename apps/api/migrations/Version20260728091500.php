<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728091500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create PlaceDiscovery tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE place_discovery_areas (
    id UUID PRIMARY KEY, name VARCHAR(160) NOT NULL, enabled BOOLEAN NOT NULL DEFAULT FALSE,
    country_code CHAR(2) NOT NULL, center_latitude DOUBLE PRECISION NOT NULL,
    center_longitude DOUBLE PRECISION NOT NULL, radius_km DOUBLE PRECISION NOT NULL,
    bbox_west DOUBLE PRECISION NOT NULL, bbox_south DOUBLE PRECISION NOT NULL,
    bbox_east DOUBLE PRECISION NOT NULL, bbox_north DOUBLE PRECISION NOT NULL,
    minimum_confidence DOUBLE PRECISION NOT NULL DEFAULT 0.8,
    maximum_candidates_per_run INT NOT NULL DEFAULT 100,
    discovery_profile VARCHAR(80) NOT NULL DEFAULT 'family-v1',
    last_successful_release VARCHAR(40), created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL, version INT NOT NULL DEFAULT 1,
    CONSTRAINT chk_discovery_area_lat CHECK (center_latitude BETWEEN -90 AND 90),
    CONSTRAINT chk_discovery_area_lon CHECK (center_longitude BETWEEN -180 AND 180),
    CONSTRAINT chk_discovery_area_radius CHECK (radius_km BETWEEN 0.1 AND 25),
    CONSTRAINT chk_discovery_area_confidence CHECK (minimum_confidence BETWEEN 0 AND 1),
    CONSTRAINT chk_discovery_area_cap CHECK (maximum_candidates_per_run BETWEEN 1 AND 1000),
    CONSTRAINT chk_discovery_area_country CHECK (country_code ~ '^[A-Z]{2}$')
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE place_discovery_runs (
    id UUID PRIMARY KEY, source VARCHAR(40) NOT NULL, source_release VARCHAR(40) NOT NULL,
    area_id UUID NOT NULL REFERENCES place_discovery_areas(id) ON DELETE RESTRICT,
    attempt INT NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL, started_at TIMESTAMPTZ,
    completed_at TIMESTAMPTZ, requested_by VARCHAR(160), discovered_count INT NOT NULL DEFAULT 0,
    inserted_count INT NOT NULL DEFAULT 0, updated_count INT NOT NULL DEFAULT 0,
    duplicate_count INT NOT NULL DEFAULT 0, skipped_count INT NOT NULL DEFAULT 0,
    failed_count INT NOT NULL DEFAULT 0, error_summary TEXT, checkpoint JSONB,
    created_at TIMESTAMPTZ NOT NULL,
    CONSTRAINT uq_discovery_run UNIQUE (source, source_release, area_id, attempt),
    CONSTRAINT chk_discovery_run_status CHECK (status IN ('QUEUED','RUNNING','COMPLETED','PARTIAL','FAILED','CANCELLED'))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE place_candidates (
    id UUID PRIMARY KEY, discovery_run_id UUID REFERENCES place_discovery_runs(id) ON DELETE SET NULL,
    source VARCHAR(40) NOT NULL, external_id VARCHAR(255) NOT NULL, source_release VARCHAR(40) NOT NULL,
    source_record_version VARCHAR(80), source_payload_hash CHAR(64) NOT NULL, source_snapshot JSONB NOT NULL,
    name VARCHAR(255) NOT NULL, normalized_name VARCHAR(255) NOT NULL,
    address_line1 VARCHAR(255), address_line2 VARCHAR(255), postal_code VARCHAR(32), locality VARCHAR(160),
    country_code CHAR(2), latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL,
    website VARCHAR(2048), normalized_website_host VARCHAR(255), phone VARCHAR(80), normalized_phone VARCHAR(32),
    source_categories JSONB NOT NULL, suggested_place_category_id UUID REFERENCES categories(id) ON DELETE SET NULL,
    confidence DOUBLE PRECISION, operating_status VARCHAR(40), discovery_score SMALLINT NOT NULL,
    discovery_reasons JSONB NOT NULL, duplicate_score SMALLINT, duplicate_reasons JSONB,
    possible_duplicate_place_id UUID REFERENCES places(id) ON DELETE SET NULL,
    status VARCHAR(24) NOT NULL, manually_edited_at TIMESTAMPTZ, source_changed_after_edit BOOLEAN NOT NULL DEFAULT FALSE,
    source_closed_review_required BOOLEAN NOT NULL DEFAULT FALSE, first_seen_at TIMESTAMPTZ NOT NULL,
    last_seen_at TIMESTAMPTZ NOT NULL, reviewed_by VARCHAR(160), reviewed_at TIMESTAMPTZ,
    rejection_reason TEXT, approved_place_id UUID REFERENCES places(id) ON DELETE SET NULL,
    version INT NOT NULL DEFAULT 1, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL,
    CONSTRAINT uq_place_candidate_source UNIQUE (source, external_id),
    CONSTRAINT chk_candidate_status CHECK (status IN ('PENDING','NEEDS_MAPPING','POSSIBLE_DUPLICATE','APPROVED','REJECTED','DUPLICATE','STALE','IMPORT_FAILED')),
    CONSTRAINT chk_candidate_coordinates CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180),
    CONSTRAINT chk_candidate_confidence CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1),
    CONSTRAINT chk_candidate_scores CHECK (discovery_score BETWEEN 0 AND 100 AND (duplicate_score IS NULL OR duplicate_score BETWEEN 0 AND 100)),
    CONSTRAINT chk_candidate_snapshot_size CHECK (octet_length(source_snapshot::text) <= 32768)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE place_source_links (
    id UUID PRIMARY KEY, place_id UUID NOT NULL REFERENCES places(id) ON DELETE RESTRICT,
    source VARCHAR(40) NOT NULL, external_id VARCHAR(255) NOT NULL, source_release VARCHAR(40) NOT NULL,
    first_linked_at TIMESTAMPTZ NOT NULL, last_seen_at TIMESTAMPTZ NOT NULL, last_payload_hash CHAR(64) NOT NULL,
    CONSTRAINT uq_place_source_external UNIQUE (source, external_id),
    CONSTRAINT uq_place_source_place UNIQUE (place_id, source)
)
SQL);
        $this->addSql('CREATE INDEX idx_candidate_status_created ON place_candidates (status, created_at DESC)');
        $this->addSql('CREATE INDEX idx_candidate_locality ON place_candidates (locality)');
        $this->addSql('CREATE INDEX idx_candidate_category ON place_candidates (suggested_place_category_id)');
        $this->addSql('CREATE INDEX idx_candidate_release ON place_candidates (source_release)');
        $this->addSql('CREATE INDEX idx_candidate_website ON place_candidates (normalized_website_host) WHERE normalized_website_host IS NOT NULL');
        $this->addSql('CREATE INDEX idx_candidate_phone ON place_candidates (normalized_phone) WHERE normalized_phone IS NOT NULL');
        $this->addSql('CREATE INDEX idx_candidate_coordinates ON place_candidates (latitude, longitude)');
        $this->addSql('CREATE INDEX idx_discovery_run_status ON place_discovery_runs (status, created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE place_source_links');
        $this->addSql('DROP TABLE place_candidates');
        $this->addSql('DROP TABLE place_discovery_runs');
        $this->addSql('DROP TABLE place_discovery_areas');
    }
}
