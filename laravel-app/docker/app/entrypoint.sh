#!/bin/sh
set -e

cd /var/www/html

MODE="${APP_BOOTSTRAP_MODE:-full}"
SECRETS=storage/app/.docker-secrets

# Ensure local cache directory is writable by PHP-FPM/workers without touching shared storage concurrently.
mkdir -p bootstrap/cache
chown -R www-data:www-data bootstrap/cache

if [ "$MODE" = "full" ] || [ "$MODE" = "init" ]; then
    mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs

    if [ ! -f "$SECRETS" ]; then
        TMP_SECRETS="${SECRETS}.tmp.$$"
        {
            echo "GENERATED_APP_KEY=base64:$(head -c 32 /dev/urandom | base64)"
            echo "GENERATED_JWT_SECRET=$(head -c 48 /dev/urandom | base64 | tr -d '\n')"
        } > "$TMP_SECRETS"
        chmod 600 "$TMP_SECRETS"
        mv "$TMP_SECRETS" "$SECRETS"
    fi

    chown -R www-data:www-data storage
fi

if [ -f "$SECRETS" ]; then
    . "./$SECRETS"
    export APP_KEY="${APP_KEY:-$GENERATED_APP_KEY}"
    export JWT_SECRET="${JWT_SECRET:-$GENERATED_JWT_SECRET}"
fi

if [ "$MODE" = "init" ]; then
    php artisan migrate --force
    php artisan db:seed --force
    exit 0
fi

if [ "$1" = "php-fpm" ]; then
    if [ "$MODE" = "full" ]; then
        php artisan migrate --force
        php artisan db:seed --force
    fi
    php artisan config:cache
    php artisan route:cache
fi

exec "$@"