# Multi-stage build for the Laravel API.
#
# Stage 1 (vendor): produce a slim, production vendor/ via composer.
# Stage 2 (app):   php-fpm + nginx + supervisord in a single container.
#                  Fly.io expects one image per app; multi-process via
#                  supervisord is simpler than Fly's [processes] split
#                  and keeps cron-ish work (queue:work, schedule:work)
#                  next to the webserver.

# ---- Stage 1: vendor ----
# Base images pinned to digests so a hijacked tag can't silently push
# malicious code into our prod image. Bump digests deliberately when
# you want a newer base; check with `docker pull <tag>` and read the
# image release notes before changing.
FROM composer:2@sha256:02062f7719ec9433a9d4256cfba1c792db96dc9db60a4a92e48264c9e166b877 AS vendor

WORKDIR /app

# Copy only the manifests first so the layer caches across code changes.
COPY composer.json composer.lock ./

# --no-scripts: postinstall scripts (artisan package:discover) need the
# full app tree, which we don't have yet. We'll re-run them in stage 2.
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader

# Copy the rest and run scripts now that artisan is reachable.
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative

# ---- Stage 2: runtime ----
FROM php:8.2-fpm-alpine@sha256:89ca299cb45f8a63a28fd4e564a9d6b916ae4b1fc5752b4e0b89b4ed688994a1 AS app

# System deps + PHP extensions Laravel needs at runtime.
#   pdo_pgsql/pgsql  → Postgres (Supabase in prod, pgvector locally)
#   intl              → Carbon/format locale helpers
#   bcmath            → encrypted-attribute integer ops
#   gd + zip          → dompdf rendering + composer cache
#   opcache           → standard prod speedup
RUN apk add --no-cache \
        nginx \
        supervisor \
        postgresql-libs \
        icu-libs \
        libzip \
        freetype \
        libpng \
        libjpeg-turbo \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        icu-dev \
        libzip-dev \
        freetype-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        intl \
        bcmath \
        zip \
        gd \
        opcache \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

WORKDIR /var/www/html

# Copy the built application from the vendor stage.
COPY --from=vendor /app /var/www/html

# Container config: nginx + php-fpm + supervisord + entrypoint.
COPY docker/nginx.conf       /etc/nginx/nginx.conf
COPY docker/php-fpm.conf     /usr/local/etc/php-fpm.d/zz-overrides.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh    /usr/local/bin/entrypoint.sh

# Production php.ini defaults — Laravel docs' recommended values.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /run/nginx \
    && chown -R www-data:www-data storage bootstrap/cache

# Fly's [[services]] config points at port 80; nginx listens there.
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
