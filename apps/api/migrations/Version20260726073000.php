<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726073000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Canonicalize active initial-post reports to their forum thread';
    }

    public function up(Schema $schema): void
    {
        // Preserve the existing case as the canonical case when a collision exists.
        // A duplicate with audit references is closed rather than deleted.
        $this->addSql(<<<'SQL'
DO $$
DECLARE report_row RECORD;
BEGIN
    FOR report_row IN
        SELECT r.id, r.reporter_id, p.thread_id
        FROM content_reports r
        JOIN forum_posts p ON p.id = r.target_id
        WHERE r.target_type = 'FORUM_POST'
          AND p.is_initial = true
          AND r.status IN ('OPEN', 'IN_REVIEW')
        ORDER BY r.created_at ASC, r.id ASC
    LOOP
        IF EXISTS (
            SELECT 1 FROM content_reports canonical
            WHERE canonical.reporter_id = report_row.reporter_id
              AND canonical.target_id = report_row.thread_id
              AND canonical.target_type = 'FORUM_THREAD'
              AND canonical.status IN ('OPEN', 'IN_REVIEW')
        ) THEN
            UPDATE content_reports
            SET status = 'RESOLVED', resolved_at = COALESCE(resolved_at, created_at), claimed_by = NULL, claimed_at = NULL
            WHERE id = report_row.id;
        ELSE
            UPDATE content_reports
            SET target_type = 'FORUM_THREAD', target_id = report_row.thread_id
            WHERE id = report_row.id;
        END IF;
    END LOOP;
END $$;
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->write('Rollback cannot safely infer whether a canonical FORUM_THREAD report originated from the initial post; no reverse data rewrite is performed.');
    }
}
