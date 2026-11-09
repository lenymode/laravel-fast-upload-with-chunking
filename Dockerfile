FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev nodejs npm $PHPIZE_DEPS \
    && docker-php-ext-install pcntl zip \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php-upload.ini /usr/local/etc/php/conf.d/uploads.ini
WORKDIR /app
COPY . .
RUN composer install --no-interaction --prefer-dist && npm install && npm run build \
    && cp -n .env.example .env && php artisan key:generate --force \
    && mkdir -p storage/framework/{cache/data,sessions,views,upload-locks} storage/app/private \
    && chmod -R a+rw storage bootstrap/cache
EXPOSE 8000
