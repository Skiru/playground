FROM composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 AS vendor
WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist \
    --ignore-platform-req=ext-intl --ignore-platform-req=ext-pgsql

FROM dunglas/frankenphp:php8.5-bookworm@sha256:cd7a5db256e74255bb50edf57b19e1bc6f57f91557d7bb864cd76e89543b6727 AS base
RUN apt-get update && apt-get upgrade -y && rm -rf /var/lib/apt/lists/*
RUN install-php-extensions pdo_pgsql pgsql intl opcache zip gd
WORKDIR /app
COPY infra/caddy/Caddyfile /etc/frankenphp/Caddyfile

FROM base AS development
COPY --from=composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 /usr/bin/composer /usr/bin/composer
COPY apps/api/ ./
RUN composer install --no-interaction --prefer-dist

FROM base AS production-build
ENV APP_ENV=prod APP_DEBUG=0
COPY --from=composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY apps/api/ ./
RUN APP_SECRET=build-time-placeholder DATABASE_URL='postgresql://build:build@database:5432/build?serverVersion=18&charset=utf8' \
    composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && APP_SECRET=build-time-placeholder DATABASE_URL='postgresql://build:build@database:5432/build?serverVersion=18&charset=utf8' \
    php bin/console cache:warmup --env=prod --no-debug \
    && php -r "require 'vendor/autoload.php'; if (!class_exists(App\\Kernel::class)) { exit(1); }"

FROM base AS production
ENV APP_ENV=prod APP_DEBUG=0

LABEL org.opencontainers.image.source="https://github.com/Skiru/playground"
LABEL org.opencontainers.image.revision="2338908d630973138a6d9fd27d2ae8d758ba6d50"
LABEL org.opencontainers.image.created="2026-07-17T09:00:00Z"
LABEL org.opencontainers.image.version="1.0.0"
LABEL org.opencontainers.image.title="family-places-api"
LABEL org.opencontainers.image.description="FamilyPlaces backend platform service"

COPY --from=production-build --chown=www-data:www-data /app /app
RUN chown -R www-data:www-data /app \
    && chmod -R u=rwX,g=rX,o= /app \
    && chmod -R u=rwX,g=rwX /app/var
USER www-data
