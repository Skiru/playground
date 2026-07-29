<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Close PlaceDiscovery run, provenance, review, and audit integrity gaps';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE place_discovery_runs
    ADD retry_of_run_id UUID REFERENCES place_discovery_runs(id) ON DELETE SET NULL,
    ADD trigger_type VARCHAR(24) NOT NULL DEFAULT 'DISPATCH',
    ADD worker_id VARCHAR(160),
    ADD last_heartbeat_at TIMESTAMPTZ,
    ADD lease_expires_at TIMESTAMPTZ,
    ADD transport_delivery_count INT NOT NULL DEFAULT 0,
    ADD linked_count INT NOT NULL DEFAULT 0,
    ADD malformed_count INT NOT NULL DEFAULT 0,
    ADD duration_ms INT,
    ADD skipped_reasons JSONB NOT NULL DEFAULT '{}'::jsonb,
    ADD error_samples JSONB NOT NULL DEFAULT '[]'::jsonb,
    ADD CONSTRAINT chk_discovery_run_trigger CHECK (trigger_type IN ('DISPATCH','HUMAN_RETRY','SYNC')),
    ADD CONSTRAINT chk_discovery_run_lease CHECK (lease_expires_at IS NULL OR last_heartbeat_at IS NOT NULL),
    ADD CONSTRAINT chk_discovery_run_delivery_count CHECK (transport_delivery_count >= 0)
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE place_candidates
    ADD source_provenance JSONB NOT NULL DEFAULT '[]'::jsonb,
    ADD suggested_city_id UUID REFERENCES cities(id) ON DELETE SET NULL,
    ADD city_selection_source VARCHAR(16),
    ADD indoor BOOLEAN,
    ADD outdoor BOOLEAN,
    ADD free_entry BOOLEAN,
    ADD possible_duplicate_candidate_ids JSONB NOT NULL DEFAULT '[]'::jsonb,
    ADD CONSTRAINT chk_candidate_city_source CHECK (city_selection_source IS NULL OR city_selection_source IN ('AUTO','ADMIN')),
    ADD CONSTRAINT chk_candidate_place_environment CHECK (indoor IS NULL OR outdoor IS NULL OR indoor OR outdoor),
    ADD CONSTRAINT chk_candidate_provenance_size CHECK (octet_length(source_provenance::text) <= 16384)
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE place_source_links
    ADD source_provenance JSONB NOT NULL DEFAULT '[]'::jsonb,
    ADD CONSTRAINT chk_source_link_provenance_size CHECK (octet_length(source_provenance::text) <= 16384)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE place_candidate_audit_events (
    id UUID PRIMARY KEY,
    candidate_id UUID NOT NULL REFERENCES place_candidates(id) ON DELETE RESTRICT,
    discovery_run_id UUID REFERENCES place_discovery_runs(id) ON DELETE SET NULL,
    actor_type VARCHAR(16) NOT NULL,
    actor_id VARCHAR(160),
    action VARCHAR(48) NOT NULL,
    previous_status VARCHAR(24),
    next_status VARCHAR(24),
    changed_fields JSONB NOT NULL DEFAULT '[]'::jsonb,
    reason VARCHAR(1000),
    source_release VARCHAR(40),
    correlation_id VARCHAR(160),
    created_at TIMESTAMPTZ NOT NULL,
    CONSTRAINT chk_candidate_audit_actor CHECK (actor_type IN ('SYSTEM','ADMIN')),
    CONSTRAINT chk_candidate_audit_fields_size CHECK (octet_length(changed_fields::text) <= 8192)
)
SQL);
        $this->addSql('CREATE INDEX idx_discovery_run_lease ON place_discovery_runs (status, lease_expires_at)');
        $this->addSql('CREATE INDEX idx_candidate_city ON place_candidates (suggested_city_id)');
        $this->addSql('CREATE INDEX idx_candidate_audit_history ON place_candidate_audit_events (candidate_id, created_at, id)');
        $this->addSql(<<<'SQL'
CREATE FUNCTION reject_place_candidate_audit_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'place candidate audit events are append-only';
END;
$$ LANGUAGE plpgsql
SQL);
        $this->addSql('CREATE TRIGGER place_candidate_audit_no_update BEFORE UPDATE OR DELETE ON place_candidate_audit_events FOR EACH ROW EXECUTE FUNCTION reject_place_candidate_audit_mutation()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER place_candidate_audit_no_update ON place_candidate_audit_events');
        $this->addSql('DROP FUNCTION reject_place_candidate_audit_mutation()');
        $this->addSql('DROP TABLE place_candidate_audit_events');
        $this->addSql('ALTER TABLE place_source_links DROP CONSTRAINT chk_source_link_provenance_size, DROP source_provenance');
        $this->addSql('ALTER TABLE place_candidates DROP CONSTRAINT chk_candidate_provenance_size, DROP CONSTRAINT chk_candidate_place_environment, DROP CONSTRAINT chk_candidate_city_source, DROP possible_duplicate_candidate_ids, DROP free_entry, DROP outdoor, DROP indoor, DROP city_selection_source, DROP suggested_city_id, DROP source_provenance');
        $this->addSql('ALTER TABLE place_discovery_runs DROP CONSTRAINT chk_discovery_run_delivery_count, DROP CONSTRAINT chk_discovery_run_lease, DROP CONSTRAINT chk_discovery_run_trigger, DROP error_samples, DROP skipped_reasons, DROP duration_ms, DROP malformed_count, DROP linked_count, DROP transport_delivery_count, DROP lease_expires_at, DROP last_heartbeat_at, DROP worker_id, DROP trigger_type, DROP retry_of_run_id');
    }
}
