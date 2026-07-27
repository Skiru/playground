<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden forum initial post identity and moderation idempotency invariants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forum_posts ADD is_initial BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('UPDATE forum_posts SET is_initial = false');
        $this->addSql("WITH ranked AS (
            SELECT id, thread_id, ROW_NUMBER() OVER (PARTITION BY thread_id ORDER BY created_at ASC, id ASC) AS rank_no
            FROM forum_posts
            WHERE parent_id IS NULL
        )
        UPDATE forum_posts p
        SET is_initial = true
        FROM ranked r
        WHERE p.id = r.id AND r.rank_no = 1");
        $this->addSql("DO $$
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM forum_threads t
                LEFT JOIN forum_posts p ON p.thread_id = t.id AND p.is_initial = true
                WHERE p.id IS NULL
            ) THEN
                RAISE EXCEPTION 'Every forum thread must have exactly one initial post.';
            END IF;
        END
        $$");
        $this->addSql('ALTER TABLE forum_posts ADD CONSTRAINT chk_forum_posts_initial_root CHECK ((NOT is_initial) OR parent_id IS NULL)');
        $this->addSql('CREATE UNIQUE INDEX uq_forum_posts_initial_per_thread ON forum_posts (thread_id) WHERE is_initial = true');
        $this->addSql('CREATE INDEX idx_forum_posts_initial_status ON forum_posts (thread_id, is_initial, status)');

        $this->addSql('DROP INDEX IF EXISTS uq_one_open_report_per_target');
        $this->addSql("CREATE UNIQUE INDEX uq_one_active_report_per_target ON content_reports (reporter_id, target_id, target_type) WHERE status IN ('OPEN', 'IN_REVIEW')");

        $this->addSql('DROP INDEX IF EXISTS uq_moderation_actions_correlation');
        $this->addSql('CREATE INDEX idx_moderation_actions_correlation ON moderation_actions (correlation_id)');

        $this->addSql('CREATE TABLE moderation_idempotency_keys (
            idempotency_key VARCHAR(36) NOT NULL PRIMARY KEY,
            moderator_id UUID NOT NULL,
            endpoint VARCHAR(120) NOT NULL,
            report_id UUID NOT NULL,
            request_fingerprint CHAR(64) NOT NULL,
            outcome_status INT NOT NULL,
            outcome_code VARCHAR(50) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            CONSTRAINT fk_moderation_idempotency_report FOREIGN KEY (report_id) REFERENCES content_reports(id) ON DELETE RESTRICT
        )');
        $this->addSql('CREATE INDEX idx_moderation_idempotency_lookup ON moderation_idempotency_keys (moderator_id, report_id, created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS moderation_idempotency_keys');
        $this->addSql('DROP INDEX IF EXISTS idx_moderation_actions_correlation');
        $this->addSql('CREATE UNIQUE INDEX uq_moderation_actions_correlation ON moderation_actions (correlation_id)');
        $this->addSql('DROP INDEX IF EXISTS uq_one_active_report_per_target');
        $this->addSql("CREATE UNIQUE INDEX uq_one_open_report_per_target ON content_reports (reporter_id, target_id, target_type) WHERE status = 'OPEN'");
        $this->addSql('DROP INDEX IF EXISTS idx_forum_posts_initial_status');
        $this->addSql('DROP INDEX IF EXISTS uq_forum_posts_initial_per_thread');
        $this->addSql('ALTER TABLE forum_posts DROP CONSTRAINT IF EXISTS chk_forum_posts_initial_root');
        $this->addSql('ALTER TABLE forum_posts DROP COLUMN IF EXISTS is_initial');
    }
}
