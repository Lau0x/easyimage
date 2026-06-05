FROM php:8.3-apache

LABEL org.opencontainers.image.source="https://github.com/Lau0x/piclite" \
      org.opencontainers.image.title="PicLite" \
      org.opencontainers.image.description="Lightweight no-database image host with Docker deployment" \
      org.opencontainers.image.licenses="GPL-2.0"

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" curl exif gd mbstring opcache zip; \
    a2enmod headers rewrite; \
    echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf; \
    a2enconf servername; \
    rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/piclite.ini
COPY docker/apache-security.conf /etc/apache2/conf-available/piclite-security.conf
COPY docker/entrypoint.sh /usr/local/bin/piclite-entrypoint
COPY . /var/www/html

RUN set -eux; \
    a2enconf piclite-security; \
    mkdir -p \
        /var/www/html/i/cache \
        /var/www/html/admin/logs/counts \
        /var/www/html/admin/logs/login \
        /var/www/html/admin/logs/login-rate \
        /var/www/html/admin/logs/tasks \
        /var/www/html/admin/logs/upload \
        /var/www/html/admin/logs/upload-rate \
        /var/www/html/admin/logs/version; \
    mkdir -p /usr/src/piclite-config; \
    cp -a /var/www/html/config/. /usr/src/piclite-config/; \
    chown -R www-data:www-data /var/www/html/i /var/www/html/config /var/www/html/admin/logs; \
    find /var/www/html -type d -exec chmod 755 {} \;; \
    find /var/www/html -type f -exec chmod 644 {} \;; \
    chmod 755 /usr/local/bin/piclite-entrypoint; \
    chmod 755 /var/www/html/app/upload.php

EXPOSE 80

ENTRYPOINT ["piclite-entrypoint"]
CMD ["apache2-foreground"]
