#!/bin/sh
# Container entrypoint. Honors three call shapes:
#
#   1. No args                          → run supervisord (app VM behavior)
#   2. Args present                     → exec them (Fly release_command,
#                                          `docker run image cmd args...`)
#   3. DOCKER_RUN_MIGRATIONS=true       → migrate before either of the above
#                                          (local docker-compose only;
#                                          Fly handles this via release_command
#                                          on its own ephemeral VM)

set -e

# Volume mounts arrive owned by root. chown so php-fpm / queue workers
# running as www-data can write to storage and bootstrap/cache.
chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

# Also normalize mode. Blade compiles component views lazily into
# storage/framework/views/ via tempnam(); if mode isn't writable for
# the owner, tempnam falls back to /tmp and PHP emits an E_NOTICE
# that Laravel converts to an ErrorException — surfacing as a 500.
chmod -R u+rwX,g+rwX \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

if [ "${DOCKER_RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[entrypoint] running migrations..."
    php artisan migrate --force
fi

# Rebuild caches against live env. Cheap (<1s) on every boot, and lets
# us ship a vanilla image and customize per-environment via secrets.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Second chown after the cache commands (they ran as root and may have
# created root-owned files that workers can't read).
chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

# Passthrough: if the platform handed us a command (Fly's release_command,
# `docker run image …`, `docker exec …`), exec it. Otherwise boot the
# full webserver+workers stack.
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

exec /usr/bin/supervisord -c /etc/supervisord.conf
