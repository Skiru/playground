<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove obsolete Doctrine type comments from immutable date columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("COMMENT ON COLUMN external_identities.created_at IS ''");
        $this->addSql("COMMENT ON COLUMN external_identities.last_used_at IS ''");
        $this->addSql("COMMENT ON COLUMN visits.visited_on IS ''");
        $this->addSql("COMMENT ON COLUMN visits.created_at IS ''");
        $this->addSql("COMMENT ON COLUMN visits.updated_at IS ''");
        $this->addSql("COMMENT ON COLUMN favorites.created_at IS ''");
    }

    public function down(Schema $schema): void
    {
        $comment = '(DC2Type:datetime_immutable)';
        $this->addSql("COMMENT ON COLUMN external_identities.created_at IS '{$comment}'");
        $this->addSql("COMMENT ON COLUMN external_identities.last_used_at IS '{$comment}'");
        $this->addSql("COMMENT ON COLUMN visits.visited_on IS '{$comment}'");
        $this->addSql("COMMENT ON COLUMN visits.created_at IS '{$comment}'");
        $this->addSql("COMMENT ON COLUMN visits.updated_at IS '{$comment}'");
        $this->addSql("COMMENT ON COLUMN favorites.created_at IS '{$comment}'");
    }
}
