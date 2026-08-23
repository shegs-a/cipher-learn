# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — PHP dependencies (Composer). Kept separate so Composer itself is
# never present in the runtime image.
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# --no-scripts: artisan isn't available yet (no app code copied). Autoloader is
# optimised; dev dependencies are excluded from the production image.
#
# --ignore-platform-req=ext-intl: this builder is the `composer:2` image, whose
# PHP has no intl extension, but Filament (filament/support) hard-requires it.
# The req is satisfied where it matters — the runtime stage below installs intl —
# so the builder only needs to resolve and lay down the packages. Without this the
# build fails here even though the shipped image is correct.
RUN composer install \
        --no-dev \
        --no-scripts \
        --prefer-dist \
        --no-interaction \
        --optimize-autoloader \
        --ignore-platform-req=ext-intl

# ---------------------------------------------------------------------------
# Stage 2 — Frontend assets (Vite + Tailwind v3). Node is a build-time only
# dependency and never ships in the runtime image.
# ---------------------------------------------------------------------------
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js postcss.config.js tailwind.config.js ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3 — Runtime. php-fpm on Debian (bookworm), non-root, no build tooling.
#
# Debian over Alpine deliberately: install-php-extensions uses prebuilt apt
# packages here instead of compiling intl/gd/redis from source, so the build is
# minutes not tens of minutes and doesn't depend on Alpine's package CDN. The
# helper still strips its build dependencies afterwards, so no compilers ship in
# the final image.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm AS app

# Robust PHP extension installation (handles system libs for us).
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        pdo_mysql \
        redis \
        gd \
        zip \
        bcmath \
        intl \
        pcntl \
        opcache

WORKDIR /var/www/html

# Engine tuning (env-independent) baked in; runtime config stays in env vars.
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-cipherlearn.ini

# Application code, then dependencies and built assets from the earlier stages.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Run as a non-root user. php:fpm (Debian) ships `www-data` as uid/gid 33.
RUN chown -R www-data:www-data storage bootstrap/cache
USER www-data

EXPOSE 9000
CMD ["php-fpm"]
