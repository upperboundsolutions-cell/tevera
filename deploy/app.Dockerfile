FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY platform/package*.json ./
RUN npm ci
COPY platform/ ./
RUN npm run build

FROM php:8.3-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libicu-dev libonig-dev unzip gosu \
    && docker-php-ext-install pdo_mysql intl zip bcmath mbstring pcntl opcache \
    && a2enmod rewrite headers && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY platform/ ./
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache
COPY --from=assets /app/public/build ./public/build
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/tevera.ini
COPY deploy/app-entrypoint.sh /usr/local/bin/tevera-entrypoint
RUN chmod +x /usr/local/bin/tevera-entrypoint
ENTRYPOINT ["tevera-entrypoint"]
CMD ["apache2-foreground"]
