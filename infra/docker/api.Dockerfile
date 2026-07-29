FROM dunglas/frankenphp:builder@sha256:fb8a2d3de8d89e515cfc1e2421f267d39d8c2e4dfcaab425e3e81f2be43d7f81 AS frankenphp-builder
COPY --from=caddy:builder@sha256:198d47eaee306d4d0c38a9960c89ff2c959aa29ad51d3e2dafa3e93ac961782a /usr/bin/xcaddy /usr/bin/xcaddy
RUN CGO_ENABLED=1 \
    XCADDY_SETCAP=1 \
    XCADDY_GO_BUILD_FLAGS="-ldflags='-w -s' -tags=nobadger,nomysql,nopgx" \
    CGO_CFLAGS="$(php-config --includes)" \
    CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs)" \
    xcaddy build \
        --output /usr/local/bin/frankenphp \
        --with github.com/dunglas/frankenphp=./ \
        --with github.com/dunglas/frankenphp/caddy=./caddy/ \
        --with github.com/dunglas/caddy-cbrotli \
        --with github.com/dunglas/mercure/caddy \
        --with github.com/dunglas/vulcain/caddy \
        --replace github.com/getkin/kin-openapi=github.com/getkin/kin-openapi@v0.144.0 \
        --replace google.golang.org/grpc=google.golang.org/grpc@v1.82.1

FROM dunglas/frankenphp:php8.5-bookworm@sha256:9c07e0c60c8f856e3730c618fa2376ccb7f2493c1379f7bbe8737d89531f2d2a AS vendor
RUN apt-get update && apt-get upgrade -y && rm -rf /var/lib/apt/lists/*
RUN install-php-extensions pdo_pgsql pgsql pcntl intl opcache zip gd exif
COPY --from=composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

FROM dunglas/frankenphp:php8.5-bookworm@sha256:9c07e0c60c8f856e3730c618fa2376ccb7f2493c1379f7bbe8737d89531f2d2a AS base
COPY --from=frankenphp-builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp
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
    php bin/console assets:install public --env=prod --no-debug \
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
# hadolint ignore=DL3008
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
