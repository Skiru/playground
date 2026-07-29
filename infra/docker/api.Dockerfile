FROM dunglas/frankenphp:php8.5-bookworm@sha256:cd7a5db256e74255bb50edf57b19e1bc6f57f91557d7bb864cd76e89543b6727 AS vendor
RUN apt-get update && apt-get upgrade -y && rm -rf /var/lib/apt/lists/*
RUN install-php-extensions pdo_pgsql pgsql pcntl intl opcache zip gd exif
COPY --from=composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

FROM dunglas/frankenphp:php8.5-bookworm@sha256:cd7a5db256e74255bb50edf57b19e1bc6f57f91557d7bb864cd76e89543b6727 AS base
RUN apt-get update && apt-get upgrade -y && rm -rf /var/lib/apt/lists/*
RUN install-php-extensions pdo_pgsql pgsql pcntl intl opcache zip gd exif
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
    && composer check-platform-reqs --no-dev \
    && APP_SECRET=build-time-placeholder DATABASE_URL='postgresql://build:build@database:5432/build?serverVersion=18&charset=utf8' \
    php bin/console cache:warmup --env=prod --no-debug \
    && php -r "require 'vendor/autoload.php'; if (!class_exists(App\\Kernel::class)) { exit(1); }"

FROM base AS production
ENV APP_ENV=prod APP_DEBUG=0

ARG OCI_SOURCE=https://github.com/Skiru/playground
ARG OCI_REVISION
ARG OCI_CREATED
ARG OCI_VERSION
LABEL org.opencontainers.image.source=$OCI_SOURCE \
    org.opencontainers.image.revision=$OCI_REVISION \
    org.opencontainers.image.created=$OCI_CREATED \
    org.opencontainers.image.version=$OCI_VERSION \
    org.opencontainers.image.title="family-places-api" \
    org.opencontainers.image.description="FamilyPlaces backend platform service"

COPY --from=production-build --chown=www-data:www-data /app /app
RUN chown -R www-data:www-data /app \
    && chmod -R u=rwX,g=rX,o= /app \
    && chmod -R u=rwX,g=rwX /app/var
USER www-data

FROM production AS discovery
USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-pip \
    && rm -rf /var/lib/apt/lists/*
COPY tools/place-discovery/requirements.txt /opt/familyplaces/requirements.txt
RUN pip3 install --break-system-packages --no-cache-dir -r /opt/familyplaces/requirements.txt \
    && pip3 check
COPY tools/place-discovery/overture_helper.py /opt/familyplaces/overture_helper.py
COPY tools/place-discovery/NOTICE /opt/familyplaces/NOTICE
COPY NOTICE /opt/familyplaces/PROJECT-NOTICE
COPY LICENSES/Apache-2.0.txt /opt/familyplaces/Apache-2.0.txt
RUN chmod 0555 /opt/familyplaces/overture_helper.py
USER www-data
