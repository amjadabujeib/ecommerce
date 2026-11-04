#!/bin/sh
set -e

cd /var/www/html

# The storage volume starts empty on first run.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

# APP_KEY and JWT_SECRET are generated once and kept on the storage volume, so
# sessions and tokens survive container restarts. Values passed in the
# environment always win.
SECRETS=storage/app/.docker-secrets

if [ ! -f "$SECRETS" ]; then
    {
        echo "GENERATED_APP_KEY=base64:$(head -c 32 /dev/urandom | base64)"
        echo "GENERATED_JWT_SECRET=$(head -c 48 /dev/urandom | base64 | tr -d '\n')"
    } > "$SECRETS"
    chmod 600 "$SECRETS"
fi

. "./$SECRETS"
export APP_KEY="${APP_KEY:-$GENERATED_APP_KEY}"
export JWT_SECRET="${JWT_SECRET:-$GENERATED_JWT_SECRET}"

chown -R www-data:www-data storage bootstrap/cache

if [ "$1" = "php-fpm" ]; then
    php artisan migrate --force
    php artisan db:seed --force
    php artisan config:cache
    php artisan route:cache
fi

exec "$@"
