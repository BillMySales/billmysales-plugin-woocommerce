# Development tools image (make targets run in it, not on the host's PHP):
# PHP CLI at the lowest version the plugin supports, Composer, Xdebug (test
# coverage), zip.
ARG PHP_VERSION=7.4
FROM php:${PHP_VERSION}-cli-alpine
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# Installs the Xdebug release that supports this PHP version.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions xdebug && apk add --no-cache git unzip zip
ENV COMPOSER_HOME=/tmp/composer COMPOSER_CACHE_DIR=/tmp/composer/cache HOME=/tmp
WORKDIR /app
