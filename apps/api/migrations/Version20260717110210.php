<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260717110210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create external_identities table and migrate existing User.googleSubject values.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE external_identities (id UUID NOT NULL, user_id UUID NOT NULL, provider VARCHAR(255) NOT NULL, provider_subject VARCHAR(255) NOT NULL, provider_email VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN external_identities.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN external_identities.last_used_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX uniq_external_identities_provider_subject ON external_identities (provider, provider_subject)');
        $this->addSql('CREATE INDEX idx_external_identities_user_id ON external_identities (user_id)');
        $this->addSql('CREATE INDEX idx_external_identities_provider_email ON external_identities (provider, provider_email)');
        $this->addSql('ALTER TABLE external_identities ADD CONSTRAINT FK_E_IDENTITIES_USER_ID FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        // Migrate existing googleSubject values
        $this->addSql('INSERT INTO external_identities (id, user_id, provider, provider_subject, provider_email, created_at, last_used_at) SELECT gen_random_uuid(), id, \'GOOGLE\', google_subject, email, created_at, COALESCE(last_login_at, created_at) FROM users WHERE google_subject IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE external_identities DROP CONSTRAINT FK_E_IDENTITIES_USER_ID');
        $this->addSql('DROP TABLE external_identities');
    }
}
