#!/bin/sh
set -e

mkdir -p \
  /var/www/html/config \
  /var/www/html/i/cache \
  /var/www/html/admin/logs/counts \
  /var/www/html/admin/logs/login \
  /var/www/html/admin/logs/tasks \
  /var/www/html/admin/logs/upload \
  /var/www/html/admin/logs/version

if [ ! -f /var/www/html/config/config.php ]; then
  cp -a /usr/src/easyimage-config/. /var/www/html/config/
else
  [ -f /var/www/html/config/api_key.php ] || cp /usr/src/easyimage-config/api_key.php /var/www/html/config/api_key.php
  [ -f /var/www/html/config/config.guest.php ] || cp /usr/src/easyimage-config/config.guest.php /var/www/html/config/config.guest.php
fi

chown -R www-data:www-data /var/www/html/i /var/www/html/config /var/www/html/admin/logs

exec docker-php-entrypoint "$@"
