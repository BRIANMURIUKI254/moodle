FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
    libxml2-dev libxslt1-dev libicu-dev libpspell-dev \
    libonig-dev libcurl4-openssl-dev unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd zip intl xsl soap mysqli pdo pdo_mysql \
        opcache curl xml mbstring exif pspell

RUN a2enmod rewrite
RUN a2enmod headers
RUN a2enmod setenvif

WORKDIR /var/www/html

COPY php.ini /usr/local/etc/php/conf.d/moodle-custom.ini

RUN mkdir -p /var/www/moodledata && chown -R www-data:www-data /var/www/moodledata && chmod -R 775 /var/www/moodledata

ENV APACHE_DOCUMENT_ROOT /var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
