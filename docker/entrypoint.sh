#!/bin/sh
set -e

mkdir -p \
  /var/www/html/config \
  /var/www/html/i/cache \
  /var/www/html/admin/logs/counts \
  /var/www/html/admin/logs/login \
  /var/www/html/admin/logs/login-rate \
  /var/www/html/admin/logs/tasks \
  /var/www/html/admin/logs/upload \
  /var/www/html/admin/logs/version

if [ ! -f /var/www/html/config/config.php ]; then
  cp -a /usr/src/easyimage-config/. /var/www/html/config/
else
  [ -f /var/www/html/config/api_key.php ] || cp /usr/src/easyimage-config/api_key.php /var/www/html/config/api_key.php
  [ -f /var/www/html/config/config.guest.php ] || cp /usr/src/easyimage-config/config.guest.php /var/www/html/config/config.guest.php
fi

if [ -f /var/www/html/config/install.lock ]; then
  rm -f /var/www/html/config/install.token
elif [ -n "${EASYIMAGE_INSTALL_TOKEN:-}" ] || [ ! -f /var/www/html/config/install.token ]; then
  if [ -n "${EASYIMAGE_INSTALL_TOKEN:-}" ]; then
    install_token="$EASYIMAGE_INSTALL_TOKEN"
  else
    install_token="$(php -r 'echo bin2hex(random_bytes(16));')"
  fi
  printf '%s' "$install_token" > /var/www/html/config/install.token
  echo "EasyImage install token: $install_token"
fi

php /var/www/html/docker/hotlink-config.php

chown -R www-data:www-data /var/www/html/i /var/www/html/config /var/www/html/admin/logs

exec docker-php-entrypoint "$@"
