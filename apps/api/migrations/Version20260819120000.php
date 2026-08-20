<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce strict claim ownership database invariant on content_reports';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE content_reports SET claimed_by = NULL, claimed_at = NULL WHERE status IN ('OPEN', 'RESOLVED', 'DISMISSED')");
        $this->addSql('ALTER TABLE content_reports DROP CONSTRAINT IF EXISTS chk_content_reports_claim');
        $this->addSql("ALTER TABLE content_reports ADD CONSTRAINT chk_content_reports_claim CHECK ((status = 'IN_REVIEW' AND claimed_by IS NOT NULL AND claimed_at IS NOT NULL) OR (status IN ('OPEN', 'RESOLVED', 'DISMISSED') AND claimed_by IS NULL AND claimed_at IS NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_reports DROP CONSTRAINT IF EXISTS chk_content_reports_claim');
        $this->addSql("ALTER TABLE content_reports ADD CONSTRAINT chk_content_reports_claim CHECK ((status = 'OPEN' AND claimed_by IS NULL AND claimed_at IS NULL) OR (status = 'IN_REVIEW' AND claimed_by IS NOT NULL AND claimed_at IS NOT NULL) OR status IN ('RESOLVED', 'DISMISSED'))");
    }
}
