# --- Backend Symfony 7.4 (PHP 8.4 FPM) -------------------------------------
FROM php:8.4-fpm-alpine

# Extensions PHP nécessaires (MariaDB, intl, opcache, zip)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl opcache zip

# Composer + git (résolution de dépendances)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache git

WORKDIR /var/www/html
ENV APP_ENV=prod \
    APP_DEBUG=0

# 1. Dépendances (couche mise en cache tant que composer.lock ne change pas)
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# 2. Code applicatif + autoloader optimisé
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && mkdir -p var/cache var/log config/jwt \
 && chown -R www-data:www-data var config/jwt

# 3. Entrypoint (clés JWT, attente BDD, migrations, cache)
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
