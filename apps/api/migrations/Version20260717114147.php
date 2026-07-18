<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260717114147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create favorites and visits tables for the Personalization module.';
    }

    public function up(Schema $schema): void
    {
        // favorites table
        $this->addSql('CREATE TABLE favorites (id UUID NOT NULL, user_id UUID NOT NULL, place_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN favorites.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX uniq_favorites_user_place ON favorites (user_id, place_id)');
        $this->addSql('CREATE INDEX idx_favorites_user_id ON favorites (user_id)');
        $this->addSql('CREATE INDEX idx_favorites_place_id ON favorites (place_id)');
        $this->addSql('ALTER TABLE favorites ADD CONSTRAINT FK_FAVORITES_USER_ID FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        // visits table
        $this->addSql('CREATE TABLE visits (id UUID NOT NULL, user_id UUID NOT NULL, place_id UUID NOT NULL, visited_on TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, note TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN visits.visited_on IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN visits.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN visits.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_visits_user_id ON visits (user_id)');
        $this->addSql('CREATE INDEX idx_visits_place_id ON visits (place_id)');
        $this->addSql('CREATE INDEX idx_visits_user_visited_on ON visits (user_id, visited_on)');
        $this->addSql('ALTER TABLE visits ADD CONSTRAINT FK_VISITS_USER_ID FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE favorites DROP CONSTRAINT FK_FAVORITES_USER_ID');
        $this->addSql('ALTER TABLE visits DROP CONSTRAINT FK_VISITS_USER_ID');
        $this->addSql('DROP TABLE favorites');
        $this->addSql('DROP TABLE visits');
    }
}
