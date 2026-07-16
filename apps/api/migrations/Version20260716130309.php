<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716130309 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add GIN full-text and trigram indexes for bounded place discovery.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION immutable_unaccent(TEXT) RETURNS TEXT
            LANGUAGE SQL IMMUTABLE PARALLEL SAFE
            AS $$ SELECT public.unaccent('public.unaccent', $1) $$
            SQL);
        $this->addSql('CREATE INDEX idx_places_search_document ON places USING GIN (to_tsvector(\'simple\', immutable_unaccent(short_description || \' \' || description)))');
        $this->addSql('CREATE INDEX idx_places_name_unaccent_trgm ON places USING GIN (immutable_unaccent(name) gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_places_search_document');
        $this->addSql('DROP INDEX idx_places_name_unaccent_trgm');
        $this->addSql('DROP FUNCTION immutable_unaccent(TEXT)');
    }
}
