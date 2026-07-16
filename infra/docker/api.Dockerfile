FROM composer:2 AS vendor
WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist \
    --ignore-platform-req=ext-intl --ignore-platform-req=ext-pgsql

FROM dunglas/frankenphp:php8.5-bookworm AS base
RUN install-php-extensions pdo_pgsql pgsql intl opcache zip
WORKDIR /app
COPY infra/caddy/Caddyfile /etc/frankenphp/Caddyfile

FROM base AS development
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY apps/api/ ./
RUN composer install --no-interaction --prefer-dist

FROM base AS production-build
ENV APP_ENV=prod APP_DEBUG=0
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY apps/api/ ./
RUN APP_SECRET=build-time-placeholder DATABASE_URL='postgresql://build:build@database:5432/build?serverVersion=18&charset=utf8' \
    composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && APP_SECRET=build-time-placeholder DATABASE_URL='postgresql://build:build@database:5432/build?serverVersion=18&charset=utf8' \
    php bin/console cache:warmup --env=prod --no-debug \
    && php -r "require 'vendor/autoload.php'; if (!class_exists(App\\Kernel::class)) { exit(1); }"

FROM base AS production
ENV APP_ENV=prod APP_DEBUG=0
COPY --from=production-build --chown=www-data:www-data /app /app
RUN chown -R www-data:www-data /app \
    && chmod -R u=rwX,g=rX,o= /app \
    && chmod -R u=rwX,g=rwX /app/var
USER www-data
