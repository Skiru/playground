<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align reviewed effective source-link provenance capacity with bounded license resolutions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE place_source_links DROP CONSTRAINT chk_source_link_provenance_size');
        $this->addSql('ALTER TABLE place_source_links ADD CONSTRAINT chk_source_link_provenance_size CHECK (octet_length(source_provenance::text) <= 32768)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(false !== $this->connection->fetchOne("SELECT 1 FROM place_candidate_audit_events WHERE details <> '{}'::jsonb LIMIT 1"), 'Cannot roll back R6: retained R5 audit compliance evidence would be discarded by a further downgrade.');
        $this->abortIf(false !== $this->connection->fetchOne('SELECT 1 FROM place_candidates WHERE octet_length(source_license_resolutions::text) > 16384 LIMIT 1'), 'Cannot roll back R6: reviewed source-license resolutions exceed the R5 predecessor capacity.');
        $this->abortIf(false !== $this->connection->fetchOne('SELECT 1 FROM place_source_links WHERE octet_length(source_provenance::text) > 16384 LIMIT 1'), 'Cannot roll back R6: effective source-link provenance exceeds the predecessor capacity.');

        $this->addSql('ALTER TABLE place_source_links DROP CONSTRAINT chk_source_link_provenance_size');
        $this->addSql('ALTER TABLE place_source_links ADD CONSTRAINT chk_source_link_provenance_size CHECK (octet_length(source_provenance::text) <= 16384)');
    }
}
