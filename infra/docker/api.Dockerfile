FROM composer:2 AS vendor
WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --classmap-authoritative

FROM dunglas/frankenphp:php8.5-bookworm AS base
RUN install-php-extensions pdo_pgsql intl opcache
WORKDIR /app
COPY apps/api/ ./
COPY infra/caddy/Caddyfile /etc/frankenphp/Caddyfile

FROM base AS development
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-interaction --prefer-dist

FROM base AS production
ENV APP_ENV=prod
COPY --from=vendor /app/vendor ./vendor
RUN chown -R www-data:www-data var
USER www-data
