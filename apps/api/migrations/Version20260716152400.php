<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716152400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce explicit special-day modes and unambiguous interval boundaries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE special_opening_days ADD mode VARCHAR(20) NOT NULL DEFAULT 'closed'");
        $this->addSql("UPDATE special_opening_days d SET mode = CASE WHEN d.closed THEN 'closed' WHEN EXISTS (SELECT 1 FROM special_opening_intervals i WHERE i.special_opening_day_id=d.id) THEN 'custom' ELSE 'open_24_hours' END");
        $this->addSql('ALTER TABLE special_opening_days ALTER COLUMN mode DROP DEFAULT');
        $this->addSql('ALTER TABLE special_opening_days DROP closed');
        $this->addSql("ALTER TABLE special_opening_days ADD CONSTRAINT chk_special_opening_day_mode CHECK (mode IN ('closed', 'open_24_hours', 'custom'))");
        $this->addSql('ALTER TABLE weekly_opening_intervals ADD CONSTRAINT chk_weekly_opening_sequence CHECK (sequence > 0)');
        $this->addSql('ALTER TABLE weekly_opening_intervals ADD CONSTRAINT chk_weekly_opening_boundary CHECK ((NOT closes_next_day AND closes_at > opens_at) OR (closes_next_day AND closes_at <= opens_at))');
        $this->addSql('ALTER TABLE special_opening_intervals ADD CONSTRAINT chk_special_opening_sequence CHECK (sequence > 0)');
        $this->addSql('ALTER TABLE special_opening_intervals ADD CONSTRAINT chk_special_opening_boundary CHECK ((NOT closes_next_day AND closes_at > opens_at) OR (closes_next_day AND closes_at <= opens_at))');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION assert_special_opening_day_consistency(p_day_id UUID) RETURNS VOID
            LANGUAGE plpgsql AS $$
            DECLARE day_mode VARCHAR(20); interval_count INT;
            BEGIN
                SELECT mode INTO day_mode FROM special_opening_days WHERE id=p_day_id;
                IF NOT FOUND THEN RETURN; END IF;
                SELECT COUNT(*) INTO interval_count FROM special_opening_intervals WHERE special_opening_day_id=p_day_id;
                IF day_mode<>'custom' AND interval_count>0 THEN
                    RAISE EXCEPTION 'Special opening day mode and intervals are inconsistent';
                END IF;
            END $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION check_special_opening_interval_parent() RETURNS TRIGGER
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM assert_special_opening_day_consistency(CASE WHEN TG_OP='DELETE' THEN OLD.special_opening_day_id ELSE NEW.special_opening_day_id END);
                RETURN NULL;
            END $$
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION check_special_opening_day() RETURNS TRIGGER
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM assert_special_opening_day_consistency(NEW.id);
                RETURN NULL;
            END $$
            SQL);
        $this->addSql('CREATE CONSTRAINT TRIGGER trg_special_interval_consistency AFTER INSERT OR UPDATE OR DELETE ON special_opening_intervals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_special_opening_interval_parent()');
        $this->addSql('CREATE CONSTRAINT TRIGGER trg_special_day_consistency AFTER INSERT OR UPDATE ON special_opening_days DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_special_opening_day()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER trg_special_interval_consistency ON special_opening_intervals');
        $this->addSql('DROP TRIGGER trg_special_day_consistency ON special_opening_days');
        $this->addSql('DROP FUNCTION check_special_opening_interval_parent()');
        $this->addSql('DROP FUNCTION check_special_opening_day()');
        $this->addSql('DROP FUNCTION assert_special_opening_day_consistency(UUID)');
        $this->addSql('ALTER TABLE special_opening_intervals DROP CONSTRAINT chk_special_opening_boundary');
        $this->addSql('ALTER TABLE special_opening_intervals DROP CONSTRAINT chk_special_opening_sequence');
        $this->addSql('ALTER TABLE weekly_opening_intervals DROP CONSTRAINT chk_weekly_opening_boundary');
        $this->addSql('ALTER TABLE weekly_opening_intervals DROP CONSTRAINT chk_weekly_opening_sequence');
        $this->addSql('ALTER TABLE special_opening_days ADD closed BOOLEAN NOT NULL DEFAULT false');
        $this->addSql("UPDATE special_opening_days SET closed = (mode='closed')");
        $this->addSql('ALTER TABLE special_opening_days ALTER COLUMN closed DROP DEFAULT');
        $this->addSql('ALTER TABLE special_opening_days DROP CONSTRAINT chk_special_opening_day_mode');
        $this->addSql('ALTER TABLE special_opening_days DROP mode');
    }
}
