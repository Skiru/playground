<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create community, forum, reporting and moderation tables';
    }

    public function up(Schema $schema): void
    {
        // 1. reviews
        $this->addSql('CREATE TABLE reviews (
            id UUID NOT NULL PRIMARY KEY,
            place_id UUID NOT NULL,
            author_id UUID NOT NULL,
            rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
            body TEXT NOT NULL,
            visited_on DATE DEFAULT NULL,
            status VARCHAR(30) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            version INT NOT NULL DEFAULT 1
        )');
        $this->addSql('CREATE UNIQUE INDEX uq_active_review_per_user_place ON reviews (place_id, author_id) WHERE status IN (\'PUBLISHED\', \'HIDDEN\')');

        // 2. place_comments
        $this->addSql('CREATE TABLE place_comments (
            id UUID NOT NULL PRIMARY KEY,
            place_id UUID NOT NULL,
            author_id UUID NOT NULL,
            parent_id UUID DEFAULT NULL,
            body TEXT NOT NULL,
            status VARCHAR(30) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            version INT NOT NULL DEFAULT 1,
            FOREIGN KEY (parent_id) REFERENCES place_comments(id) ON DELETE SET NULL
        )');

        // 3. forum_categories
        $this->addSql('CREATE TABLE forum_categories (
            id UUID NOT NULL PRIMARY KEY,
            slug VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            description TEXT NOT NULL,
            city_id UUID DEFAULT NULL,
            display_order INT NOT NULL,
            active BOOLEAN NOT NULL DEFAULT true
        )');

        // 4. forum_threads
        $this->addSql('CREATE TABLE forum_threads (
            id UUID NOT NULL PRIMARY KEY,
            category_id UUID NOT NULL,
            author_id UUID NOT NULL,
            title VARCHAR(255) NOT NULL,
            status VARCHAR(30) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            locked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            pinned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            version INT NOT NULL DEFAULT 1,
            FOREIGN KEY (category_id) REFERENCES forum_categories(id) ON DELETE CASCADE
        )');

        // 5. forum_posts
        $this->addSql('CREATE TABLE forum_posts (
            id UUID NOT NULL PRIMARY KEY,
            thread_id UUID NOT NULL,
            author_id UUID NOT NULL,
            parent_id UUID DEFAULT NULL,
            body TEXT NOT NULL,
            status VARCHAR(30) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            version INT NOT NULL DEFAULT 1,
            FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
            FOREIGN KEY (parent_id) REFERENCES forum_posts(id) ON DELETE SET NULL
        )');

        // 6. content_reports
        $this->addSql('CREATE TABLE content_reports (
            id UUID NOT NULL PRIMARY KEY,
            reporter_id UUID NOT NULL,
            target_type VARCHAR(50) NOT NULL,
            target_id UUID NOT NULL,
            reason VARCHAR(50) NOT NULL,
            details TEXT DEFAULT NULL,
            status VARCHAR(30) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            resolved_by UUID DEFAULT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX uq_one_open_report_per_target ON content_reports (reporter_id, target_id, target_type) WHERE status = \'OPEN\'');

        // 7. moderation_actions
        $this->addSql('CREATE TABLE moderation_actions (
            id UUID NOT NULL PRIMARY KEY,
            moderator_id UUID NOT NULL,
            target_type VARCHAR(50) NOT NULL,
            target_id UUID NOT NULL,
            action VARCHAR(50) NOT NULL,
            reason TEXT NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            previous_status VARCHAR(30) DEFAULT NULL,
            resulting_status VARCHAR(30) NOT NULL
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS moderation_actions CASCADE');
        $this->addSql('DROP TABLE IF EXISTS content_reports CASCADE');
        $this->addSql('DROP TABLE IF EXISTS forum_posts CASCADE');
        $this->addSql('DROP TABLE IF EXISTS forum_threads CASCADE');
        $this->addSql('DROP TABLE IF EXISTS forum_categories CASCADE');
        $this->addSql('DROP TABLE IF EXISTS place_comments CASCADE');
        $this->addSql('DROP TABLE IF EXISTS reviews CASCADE');
    }
}
