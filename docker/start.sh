#!/usr/bin/env bash
set -e

export PORT="${PORT:-10000}"

envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/http.d/default.conf

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

if [ "${RUN_STARTUP_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

if [ -n "${SYSTEM_SUPERADMIN_EMAIL:-}" ] && [ -n "${SYSTEM_SUPERADMIN_PASSWORD:-}" ]; then
    php artisan system:create-master-user "${SYSTEM_SUPERADMIN_EMAIL}" "${SYSTEM_SUPERADMIN_PASSWORD}" --name="${SYSTEM_SUPERADMIN_NAME:-Mr. Robot Bolivia}" || true
fi

php artisan cache:clear || true
php artisan permission:cache-reset || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "${RUN_STARTUP_CACHE_WARM:-true}" = "true" ]; then
    php artisan performance:warm-operational-cache || true
fi

php-fpm -D
nginx -g 'daemon off;'
