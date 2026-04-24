FROM node:22-alpine AS frontend

WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .
RUN npm run build

FROM php:8.3-cli

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    curl \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    netcat-openbsd \
    && docker-php-ext-install pdo_pgsql pgsql bcmath pcntl zip intl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod +x /usr/local/bin/app-entrypoint

COPY . .
COPY --from=frontend /app/public/build /var/www/html/public/build

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-req=ext-cassandra \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["docker-php-entrypoint"]
CMD ["app-entrypoint"]
