FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    libxslt1-dev \
    libicu-dev \
    libpspell-dev \
    libonig-dev \
    libcurl4-openssl-dev \
    unzip \
    git \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        zip \
        intl \
        xsl \
        soap \
        mysqli \
        pdo \
        pdo_mysql \
        opcache \
        curl \
        xml \
        mbstring \
        exif \
        pspell \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache modules required by Moodle
RUN a2enmod rewrite headers setenvif

WORKDIR /var/www/html

COPY php.ini /usr/local/etc/php/conf.d/moodle-custom.ini

# Moodle data directory
RUN mkdir -p /var/www/moodledata \
    && chown -R www-data:www-data /var/www/moodledata \
    && chmod -R 775 /var/www/moodledata

# Moodle 5.x web root
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/000-default.conf

# Moodle router configuration
RUN cat > /etc/apache2/conf-available/moodle.conf <<'APACHEEOF'
<Directory /var/www/html/public>
    Options FollowSymLinks
    AllowOverride None
    Require all granted
    DirectoryIndex index.php
    FallbackResource /r.php
</Directory>
APACHEEOF

RUN a2enconf moodle

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html
