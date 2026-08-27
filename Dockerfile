# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — build frontend assets with Vite
# ---------------------------------------------------------------------------
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources/ ./resources/
COPY public/ ./public/

ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT
ARG VITE_REVERB_SCHEME
ENV VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME

RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2 — PHP-FPM runtime
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS runtime

# System deps + PHP extensions (PostgreSQL, Redis, pcntl)
RUN apk add --no-cache \
        postgresql-dev \
        libpq \
        curl \
        git \
        zip \
        unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install Composer dependencies first (cache-friendly layer)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts

# Copy application code
COPY . .

# Recreate writable storage dirs and discover packages
RUN mkdir -p \
        storage/app \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && php artisan package:discover --ansi

# Compiled assets from the frontend stage
COPY --from=frontend /app/public/build ./public/build

# Make the application readable/writable by the FPM user (www-data)
RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
