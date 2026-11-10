# syntax=docker/dockerfile:1

# --- Composer dependencies (production only) ---------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

COPY . .
# The compiled extension is installed by the runtime stage, not application code.
RUN rm -rf docker/php/extensions \
    && composer dump-autoload --no-dev --optimize --no-scripts

# --- PHP-FPM application -----------------------------------------------------
# The bundled extension targets this exact PHP 8.5.12 Debian/amd64 runtime.
FROM --platform=linux/amd64 php:8.5.12-fpm@sha256:c34bcf437ed61bd5e4c1114494eaf1ffd54253bce2b95d263bfa18b350ee8432 AS app

# PostgreSQL support; this small extension is independent of gRPC.
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Ship the previously compiled gRPC extension: no PECL download or C++ build.
COPY docker/php/extensions/grpc-1.85.0-php8.5-linux-amd64.so.gz /tmp/grpc.so.gz
RUN set -eu; \
    test "$(basename "$(php-config --extension-dir)")" = "no-debug-non-zts-20250925"; \
    test "$(uname -m)" = "x86_64"; \
    extension_path="$(php-config --extension-dir)/grpc.so"; \
    gzip -dc /tmp/grpc.so.gz > "$extension_path"; \
    echo "b4bc4904e6fc28ce23c3732b7f908bb4828750129efe9241d9981e5a730b72ca  $extension_path" | sha256sum -c -; \
    docker-php-ext-enable grpc; \
    php -r 'if (!extension_loaded("grpc") || phpversion("grpc") !== "1.85.0") { exit(1); }'; \
    rm /tmp/grpc.so.gz

COPY docker/php/extensions/grpc-LICENSE /usr/local/share/licenses/php-grpc/LICENSE
COPY docker/php/extensions/grpc-NOTICE.txt /usr/local/share/licenses/php-grpc/NOTICE.txt

COPY docker/php/app.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

COPY --from=vendor --chown=www-data:www-data /app /var/www/html
RUN php artisan package:discover --ansi \
    && chown -R www-data:www-data bootstrap/cache storage

COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# --- Nginx front end ---------------------------------------------------------
# Nginx serves the public assets and forwards application requests.
FROM nginx:1.29-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=vendor /app/public /var/www/html/public