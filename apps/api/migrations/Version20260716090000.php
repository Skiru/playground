<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Identity and Places schema with PostGIS discovery indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS postgis');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, email VARCHAR(255) NOT NULL, display_name VARCHAR(255) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, google_subject VARCHAR(255) DEFAULT NULL, roles JSON NOT NULL, status VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E958A03FC ON users (google_subject)');
        $this->addSql('CREATE TABLE cities (id UUID NOT NULL PRIMARY KEY, name VARCHAR(120) NOT NULL, slug VARCHAR(120) NOT NULL UNIQUE, country_code CHAR(2) NOT NULL, center geography(Point,4326) NOT NULL, latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, default_zoom SMALLINT NOT NULL, default_radius_km SMALLINT NOT NULL, timezone VARCHAR(64) NOT NULL, enabled BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
        $this->addSql('CREATE TABLE categories (id UUID NOT NULL PRIMARY KEY, name VARCHAR(120) NOT NULL, slug VARCHAR(120) NOT NULL UNIQUE, description TEXT DEFAULT NULL, icon_key VARCHAR(80) NOT NULL, enabled BOOLEAN NOT NULL, display_order INT NOT NULL)');
        $this->addSql('CREATE TABLE amenities (id UUID NOT NULL PRIMARY KEY, name VARCHAR(120) NOT NULL, slug VARCHAR(120) NOT NULL UNIQUE, amenity_group VARCHAR(80) NOT NULL, icon_key VARCHAR(80) NOT NULL, enabled BOOLEAN NOT NULL, display_order INT NOT NULL)');
        $this->addSql('CREATE TABLE places (id UUID NOT NULL PRIMARY KEY, city_id UUID NOT NULL, primary_category_id UUID NOT NULL, slug VARCHAR(160) NOT NULL UNIQUE, name VARCHAR(180) NOT NULL, normalized_name VARCHAR(180) NOT NULL, short_description VARCHAR(300) NOT NULL, description TEXT NOT NULL, status VARCHAR(40) NOT NULL, verification_status VARCHAR(40) NOT NULL, address_line1 VARCHAR(180) NOT NULL, address_line2 VARCHAR(180) DEFAULT NULL, postal_code VARCHAR(20) NOT NULL, country_code CHAR(2) NOT NULL, location geography(Point,4326) NOT NULL, latitude DOUBLE PRECISION NOT NULL, longitude DOUBLE PRECISION NOT NULL, timezone VARCHAR(64) NOT NULL, indoor BOOLEAN NOT NULL, outdoor BOOLEAN NOT NULL, free_entry BOOLEAN NOT NULL, price_description VARCHAR(255) DEFAULT NULL, website_url VARCHAR(500) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, CONSTRAINT fk_places_city FOREIGN KEY (city_id) REFERENCES cities (id), CONSTRAINT fk_places_primary_category FOREIGN KEY (primary_category_id) REFERENCES categories (id))');
        $this->addSql('CREATE INDEX idx_places_location ON places USING GIST (location)');
        $this->addSql('CREATE INDEX idx_places_status ON places (status)');
        $this->addSql('CREATE INDEX idx_places_city ON places (city_id)');
        $this->addSql('CREATE INDEX idx_places_published_at ON places (published_at)');
        $this->addSql('CREATE INDEX idx_places_primary_category ON places (primary_category_id)');
        $this->addSql('CREATE INDEX idx_places_name_trgm ON places USING GIN (normalized_name gin_trgm_ops)');
        $this->addSql('CREATE TABLE place_categories (place_id UUID NOT NULL, category_id UUID NOT NULL, PRIMARY KEY(place_id, category_id), FOREIGN KEY (place_id) REFERENCES places(id) ON DELETE CASCADE, FOREIGN KEY (category_id) REFERENCES categories(id))');
        $this->addSql('CREATE INDEX idx_place_categories_category ON place_categories (category_id)');
        $this->addSql('CREATE TABLE place_amenities (place_id UUID NOT NULL, amenity_id UUID NOT NULL, PRIMARY KEY(place_id, amenity_id), FOREIGN KEY (place_id) REFERENCES places(id) ON DELETE CASCADE, FOREIGN KEY (amenity_id) REFERENCES amenities(id))');
        $this->addSql('CREATE INDEX idx_place_amenities_amenity ON place_amenities (amenity_id)');
        $this->addSql('CREATE TABLE place_age_zones (id UUID NOT NULL PRIMARY KEY, place_id UUID NOT NULL, name VARCHAR(120) NOT NULL, min_age_months SMALLINT NOT NULL, max_age_months SMALLINT DEFAULT NULL, notes TEXT DEFAULT NULL, source_type VARCHAR(40) NOT NULL, verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, FOREIGN KEY (place_id) REFERENCES places(id) ON DELETE CASCADE, CHECK (min_age_months >= 0 AND min_age_months <= 216), CHECK (max_age_months IS NULL OR (max_age_months >= min_age_months AND max_age_months <= 216)))');
        $this->addSql('CREATE INDEX idx_age_zones_place_range ON place_age_zones (place_id, min_age_months, max_age_months)');
        $this->addSql('CREATE TABLE weekly_opening_intervals (id UUID NOT NULL PRIMARY KEY, place_id UUID NOT NULL, weekday SMALLINT NOT NULL, sequence SMALLINT NOT NULL, opens_at TIME(0) WITHOUT TIME ZONE NOT NULL, closes_at TIME(0) WITHOUT TIME ZONE NOT NULL, closes_next_day BOOLEAN NOT NULL, FOREIGN KEY (place_id) REFERENCES places(id) ON DELETE CASCADE, CHECK (weekday BETWEEN 1 AND 7), UNIQUE(place_id, weekday, sequence))');
        $this->addSql('CREATE INDEX idx_weekly_opening_lookup ON weekly_opening_intervals (place_id, weekday)');
        $this->addSql('CREATE TABLE special_opening_days (id UUID NOT NULL PRIMARY KEY, place_id UUID NOT NULL, local_date DATE NOT NULL, closed BOOLEAN NOT NULL, note TEXT DEFAULT NULL, FOREIGN KEY (place_id) REFERENCES places(id) ON DELETE CASCADE, UNIQUE(place_id, local_date))');
        $this->addSql('CREATE TABLE special_opening_intervals (id UUID NOT NULL PRIMARY KEY, special_opening_day_id UUID NOT NULL, sequence SMALLINT NOT NULL, opens_at TIME(0) WITHOUT TIME ZONE NOT NULL, closes_at TIME(0) WITHOUT TIME ZONE NOT NULL, closes_next_day BOOLEAN NOT NULL, FOREIGN KEY (special_opening_day_id) REFERENCES special_opening_days(id) ON DELETE CASCADE, UNIQUE(special_opening_day_id, sequence))');
        $this->addSql('CREATE TABLE external_place_references (id UUID NOT NULL PRIMARY KEY, place_id UUID NOT NULL, provider VARCHAR(80) NOT NULL, external_id VARCHAR(255) NOT NULL, source_url VARCHAR(500) DEFAULT NULL, imported_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, FOREIGN KEY (place_id) REFERENCES places(id) ON DELETE CASCADE, UNIQUE(provider, external_id))');
        $this->addSql('CREATE INDEX idx_external_place ON external_place_references (place_id)');
        $this->addSql('CREATE TABLE messenger_messages (id BIGSERIAL NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_messenger_queue ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX idx_messenger_available ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX idx_messenger_delivered ON messenger_messages (delivered_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS messenger_messages, external_place_references, special_opening_intervals, special_opening_days, weekly_opening_intervals, place_age_zones, place_amenities, place_categories, places, amenities, categories, cities, users CASCADE');
    }
}
