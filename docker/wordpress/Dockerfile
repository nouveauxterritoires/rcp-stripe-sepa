ARG PHP_VERSION=8.2
FROM wordpress:php${PHP_VERSION}-apache

# Outils nécessaires au provisionnement et aux tests.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip less mariadb-client subversion curl jq \
    && rm -rf /var/lib/apt/lists/*

# Xdebug : couverture de code et débogage pas à pas.
RUN pecl install xdebug && docker-php-ext-enable xdebug
COPY xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini
COPY php.ini    /usr/local/etc/php/conf.d/zz-project.ini

# Composer.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# WP-CLI.
RUN curl -sSL -o /usr/local/bin/wp \
        https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Le conteneur tourne en root en développement ; WP-CLI doit l'accepter.
ENV WP_CLI_ALLOW_ROOT=1
