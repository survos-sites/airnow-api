# syntax=docker/dockerfile:1
FROM dunglas/frankenphp:1-php8.5 AS base
WORKDIR /app
RUN install-php-extensions pdo_sqlite intl
COPY Caddyfile /etc/caddy/Caddyfile

FROM base AS build
RUN install-php-extensions @composer
ENV COMPOSER_ALLOW_SUPERUSER=1 APP_ENV=prod APP_DEBUG=0
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev --no-interaction \
    && php bin/console cache:clear --env=prod --no-debug

FROM base AS prod
ENV APP_ENV=prod APP_DEBUG=0
COPY --from=build --chown=www-data:www-data /app /app
EXPOSE 80
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
