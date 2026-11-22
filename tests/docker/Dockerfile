# One image for the whole test environment: it runs lint and PHPUnit, serves the
# application under test and serves the fake tracker / fake GitHub over TLS.
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-cli-alpine

# pdo_mysql and pdo_pgsql are needed only by the tests of tools/convert-db.php
RUN apk add --no-cache libzip libpq nginx openssl curl \
 && apk add --no-cache --virtual .build $PHPIZE_DEPS libzip-dev postgresql-dev \
 && docker-php-ext-install -j"$(nproc)" zip pdo_mysql pdo_pgsql \
 && apk del .build \
 && curl -fsSL -o /usr/local/bin/phpunit https://phar.phpunit.de/phpunit-9.6.phar \
 && chmod +x /usr/local/bin/phpunit \
 && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
 && printf 'error_reporting=E_ALL\ndisplay_errors=On\nlog_errors=On\nerror_log=/dev/stderr\n' > "$PHP_INI_DIR/conf.d/tests.ini"

WORKDIR /app
