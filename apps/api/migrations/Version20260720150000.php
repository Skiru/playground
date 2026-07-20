<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260720150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden database lifecycle, remove destructive cascades, and add audit linkage';
    }

    public function up(Schema $schema): void
    {
        // 1. Remove destructive cascades
        $this->addSql('ALTER TABLE forum_threads DROP CONSTRAINT IF EXISTS forum_threads_category_id_fkey');
        $this->addSql('ALTER TABLE forum_threads ADD CONSTRAINT fk_forum_threads_category_id FOREIGN KEY (category_id) REFERENCES forum_categories(id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE forum_posts DROP CONSTRAINT IF EXISTS forum_posts_thread_id_fkey');
        $this->addSql('ALTER TABLE forum_posts ADD CONSTRAINT fk_forum_posts_thread_id FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE RESTRICT');

        // 2. Add report_id and correlation_id to moderation_actions
        $this->addSql('ALTER TABLE moderation_actions ADD COLUMN report_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE moderation_actions ADD COLUMN correlation_id VARCHAR(255) DEFAULT NULL');

        // Populate existing rows to satisfy NOT NULL if there are any
        $this->addSql('UPDATE moderation_actions SET correlation_id = id::text WHERE correlation_id IS NULL');
        $this->addSql('ALTER TABLE moderation_actions ALTER COLUMN correlation_id SET NOT NULL');

        // Add constraints
        $this->addSql('ALTER TABLE moderation_actions ADD CONSTRAINT fk_moderation_actions_report FOREIGN KEY (report_id) REFERENCES content_reports (id) ON DELETE RESTRICT');
        $this->addSql('CREATE UNIQUE INDEX uq_moderation_actions_correlation ON moderation_actions (correlation_id)');

        // 3. Indexes for moderation case lookup, public feed, and deterministic pagination
        $this->addSql('CREATE INDEX idx_content_reports_case_lookup ON content_reports (status, reporter_id, target_type, target_id)');
        $this->addSql('CREATE INDEX idx_forum_threads_containment ON forum_threads (id, category_id, status)');
        $this->addSql('CREATE INDEX idx_forum_posts_containment ON forum_posts (id, thread_id, status)');

        // 4. Database checks
        $this->addSql('ALTER TABLE content_reports ADD CONSTRAINT chk_content_reports_status CHECK (status IN (\'OPEN\', \'IN_REVIEW\', \'RESOLVED\', \'DISMISSED\'))');
        $this->addSql('ALTER TABLE content_reports ADD CONSTRAINT chk_content_reports_target_type CHECK (target_type IN (\'REVIEW\', \'PLACE_COMMENT\', \'FORUM_THREAD\', \'FORUM_POST\'))');
        $this->addSql('ALTER TABLE moderation_actions ADD CONSTRAINT chk_moderation_actions_action CHECK (action IN (\'HIDE\', \'REMOVE\', \'RESTORE\', \'LOCK\', \'UNLOCK\', \'PIN\', \'UNPIN\', \'DISMISS_REPORT\', \'RESOLVE_REPORT\'))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE moderation_actions DROP CONSTRAINT IF EXISTS chk_moderation_actions_action');
        $this->addSql('ALTER TABLE content_reports DROP CONSTRAINT IF EXISTS chk_content_reports_target_type');
        $this->addSql('ALTER TABLE content_reports DROP CONSTRAINT IF EXISTS chk_content_reports_status');

        $this->addSql('DROP INDEX IF EXISTS idx_forum_posts_containment');
        $this->addSql('DROP INDEX IF EXISTS idx_forum_threads_containment');
        $this->addSql('DROP INDEX IF EXISTS idx_content_reports_case_lookup');

        $this->addSql('DROP INDEX IF EXISTS uq_moderation_actions_correlation');
        $this->addSql('ALTER TABLE moderation_actions DROP CONSTRAINT IF EXISTS fk_moderation_actions_report');
        $this->addSql('ALTER TABLE moderation_actions DROP COLUMN IF EXISTS correlation_id');
        $this->addSql('ALTER TABLE moderation_actions DROP COLUMN IF EXISTS report_id');

        $this->addSql('ALTER TABLE forum_posts DROP CONSTRAINT IF EXISTS fk_forum_posts_thread_id');
        $this->addSql('ALTER TABLE forum_posts ADD CONSTRAINT forum_posts_thread_id_fkey FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE forum_threads DROP CONSTRAINT IF EXISTS fk_forum_threads_category_id');
        $this->addSql('ALTER TABLE forum_threads ADD CONSTRAINT forum_threads_category_id_fkey FOREIGN KEY (category_id) REFERENCES forum_categories(id) ON DELETE CASCADE');
    }
}
