# syntax=docker/dockerfile:1

# --- Composer dependencies (production only) ---------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# --- PHP-FPM application -----------------------------------------------------
# التغيير هنا: استخدام Debian بدلاً من Alpine
FROM php:8.5-fpm AS app

# تثبيت متطلبات pdo_pgsql
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# gRPC client for the inventory service. 
# على Debian، التجميع سيكون أسرع بكثير من Alpine
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions grpc

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
# صورة Nginx يمكن أن تبقى Alpine لأنها خفيفة ولا تحتاج تجميع إضافات
FROM nginx:1.29-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=vendor /app/public /var/www/html/public