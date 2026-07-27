<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes for community and forum tables';
    }

    public function up(Schema $schema): void
    {
        // 1. reviews performance indexes
        $this->addSql('CREATE INDEX idx_reviews_list ON reviews (place_id, status, created_at DESC, id DESC)');
        $this->addSql('CREATE INDEX idx_reviews_rating_summary ON reviews (place_id, status, rating)');

        // 2. place_comments performance indexes
        $this->addSql('CREATE INDEX idx_place_comments_parent_list ON place_comments (place_id, status, parent_id, created_at ASC, id ASC)');
        $this->addSql('CREATE INDEX idx_place_comments_reply_list ON place_comments (parent_id, status, created_at ASC, id ASC)');

        // 3. forum_threads performance indexes
        $this->addSql('CREATE INDEX idx_forum_threads_list ON forum_threads (category_id, status, pinned_at DESC, last_activity_at DESC, id DESC)');

        // 4. forum_posts performance indexes
        $this->addSql('CREATE INDEX idx_forum_posts_list ON forum_posts (thread_id, status, created_at ASC, id ASC)');

        // 5. content_reports performance indexes
        $this->addSql('CREATE INDEX idx_content_reports_status_list ON content_reports (status, created_at DESC, id DESC)');
        $this->addSql('CREATE INDEX idx_content_reports_target ON content_reports (target_type, target_id)');

        // 6. moderation_actions performance indexes
        $this->addSql('CREATE INDEX idx_moderation_actions_target ON moderation_actions (target_type, target_id, created_at DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_moderation_actions_target');
        $this->addSql('DROP INDEX idx_content_reports_target');
        $this->addSql('DROP INDEX idx_content_reports_status_list');
        $this->addSql('DROP INDEX idx_forum_posts_list');
        $this->addSql('DROP INDEX idx_forum_threads_list');
        $this->addSql('DROP INDEX idx_place_comments_reply_list');
        $this->addSql('DROP INDEX idx_place_comments_parent_list');
        $this->addSql('DROP INDEX idx_reviews_rating_summary');
        $this->addSql('DROP INDEX idx_reviews_list');
    }
}
