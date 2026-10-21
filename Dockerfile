FROM phusion/baseimage:jammy-1.0.1 as base

ENV LC_ALL C.UTF-8

ARG PHP_VERSION=8.3

RUN install_clean ca-certificates curl \
 && curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb \
 && dpkg -i /tmp/debsuryorg-archive-keyring.deb \
 && rm /tmp/debsuryorg-archive-keyring.deb \
 && . /etc/os-release \
 && echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main" > /etc/apt/sources.list.d/php.list \
 && install_clean \
      gettext \
      nginx \
      php${PHP_VERSION}-fpm \
      php${PHP_VERSION}-bcmath \
      php${PHP_VERSION}-curl \
      php${PHP_VERSION}-gmp \
      php${PHP_VERSION}-mbstring \
      php${PHP_VERSION}-sqlite3 \
      php${PHP_VERSION}-zip \
      php${PHP_VERSION}-dom \
      php${PHP_VERSION}-xml \
      php${PHP_VERSION}-intl \
      unzip

WORKDIR /var/www/html

COPY --chown=www-data --from=composer:2 /usr/bin/composer /tmp/composer
COPY composer.json composer.lock ./
RUN mkdir -p vendor \
 && chown www-data:www-data vendor \
 && COMPOSER_CACHE_DIR=/dev/null setuser www-data /tmp/composer install --no-dev --no-interaction --no-scripts --no-autoloader

COPY --chown=www-data . .

RUN COMPOSER_CACHE_DIR=/dev/null setuser www-data /tmp/composer install --no-dev --no-interaction --no-scripts --classmap-authoritative \
 && rm -rf /tmp/composer

COPY deploy/conf/nginx/sonar-customerportal.template /etc/nginx/conf.d/customerportal.template

COPY deploy/conf/php-fpm/ /etc/php/${PHP_VERSION}/fpm/

COPY deploy/conf/cron.d/* /etc/cron.d/
RUN chmod -R go-w /etc/cron.d

RUN mkdir -p /etc/my_init.d
COPY deploy/*.sh /etc/my_init.d/

RUN mkdir /etc/service/php-fpm
COPY deploy/services/php-fpm.sh /etc/service/php-fpm/run

RUN mkdir /etc/service/nginx
COPY deploy/services/nginx.sh /etc/service/nginx/run

VOLUME /var/www/html/storage
EXPOSE 80 443
