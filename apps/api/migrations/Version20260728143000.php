<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add durable discovery dispatch state and lease fencing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE place_discovery_runs
    ADD execution_token BIGINT NOT NULL DEFAULT 0,
    ADD dispatch_state VARCHAR(16) NOT NULL DEFAULT 'PENDING',
    ADD dispatch_attempts INT NOT NULL DEFAULT 0,
    ADD dispatched_at TIMESTAMPTZ,
    ADD last_dispatch_error VARCHAR(1000),
    ADD CONSTRAINT chk_discovery_execution_token CHECK (execution_token >= 0),
    ADD CONSTRAINT chk_discovery_dispatch_state CHECK (dispatch_state IN ('PENDING','DISPATCHED')),
    ADD CONSTRAINT chk_discovery_dispatch_attempts CHECK (dispatch_attempts >= 0)
SQL);
        $this->addSql("UPDATE place_discovery_runs SET dispatch_state = 'DISPATCHED', dispatched_at = created_at WHERE status <> 'QUEUED' OR transport_delivery_count > 0");
        $this->addSql('CREATE INDEX idx_discovery_pending_dispatch ON place_discovery_runs (created_at, id) WHERE status = \'QUEUED\' AND dispatch_state = \'PENDING\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_discovery_pending_dispatch');
        $this->addSql('ALTER TABLE place_discovery_runs DROP CONSTRAINT chk_discovery_dispatch_attempts, DROP CONSTRAINT chk_discovery_dispatch_state, DROP CONSTRAINT chk_discovery_execution_token, DROP last_dispatch_error, DROP dispatched_at, DROP dispatch_attempts, DROP dispatch_state, DROP execution_token');
    }
}
