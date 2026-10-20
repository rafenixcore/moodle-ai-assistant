# PHP 8.3 con Apache — requisito verificado en composer.json de Moodle 5.2 (>= 8.3.0)
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libicu-dev \
        libonig-dev libxml2-dev libxslt1-dev libcurl4-openssl-dev \
        default-mysql-client unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd intl zip soap pdo_mysql mysqli opcache xsl exif mbstring iconv \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Modulos Apache que Moodle necesita (rewrite para URLs limpias, headers)
RUN a2enmod rewrite headers

# DocumentRoot dividido de Moodle 5.x: el webroot es public/
COPY moodle.conf /etc/apache2/sites-available/000-default.conf
COPY php.ini /usr/local/etc/php/conf.d/zz-moodle.ini
