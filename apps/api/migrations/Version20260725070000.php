<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist atomic moderation case claim ownership';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_reports ADD claimed_by UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE content_reports ADD claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE content_reports SET status = 'OPEN' WHERE status = 'IN_REVIEW'");
        $this->addSql('CREATE INDEX idx_content_reports_claim_queue ON content_reports (status, claimed_by, created_at DESC, id DESC)');
        $this->addSql('CREATE INDEX idx_moderation_actions_target_history ON moderation_actions (target_type, target_id, created_at DESC, id DESC)');
        $this->addSql("ALTER TABLE content_reports ADD CONSTRAINT chk_content_reports_claim CHECK ((status = 'OPEN' AND claimed_by IS NULL AND claimed_at IS NULL) OR (status = 'IN_REVIEW' AND claimed_by IS NOT NULL AND claimed_at IS NOT NULL) OR status IN ('RESOLVED', 'DISMISSED'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_reports DROP CONSTRAINT IF EXISTS chk_content_reports_claim');
        $this->addSql('DROP INDEX IF EXISTS idx_moderation_actions_target_history');
        $this->addSql('DROP INDEX IF EXISTS idx_content_reports_claim_queue');
        $this->addSql('ALTER TABLE content_reports DROP COLUMN claimed_at');
        $this->addSql('ALTER TABLE content_reports DROP COLUMN claimed_by');
    }
}
