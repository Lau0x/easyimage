#!/bin/sh
set -eu

root=/var/www/html
dynamic_conf=/etc/apache2/conf-enabled/piclite-lite-path.conf
app_path=$(printf '%s' "${LITE_APP_PATH-}" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')

rm -f "$dynamic_conf"

case "$app_path" in
  ''|'/lite')
    ;;
  '/')
    {
      printf '%s\n' 'Alias "/i/" "/var/www/html/i/"'
      printf '%s\n' 'Alias "/" "/var/www/html/lite/"'
      printf '%s\n' '<Directory "/var/www/html/lite">' '    Require all granted' '</Directory>'
    } > "$dynamic_conf"
    ;;
  *)
    echo "Invalid LITE_APP_PATH: use /lite or /" >&2
    exit 1
    ;;
esac

mkdir -p \
  "$root/config" \
  "$root/i/cache" \
  "$root/config/lite-rate" \
  "$root/admin/logs/counts" \
  "$root/admin/logs/login" \
  "$root/admin/logs/login-rate" \
  "$root/admin/logs/lite" \
  "$root/admin/logs/tasks" \
  "$root/admin/logs/upload" \
  "$root/admin/logs/upload-rate" \
  "$root/admin/logs/version"

if [ ! -f "$root/config/config.php" ]; then
  cp -a /usr/src/piclite-config/. "$root/config/"
else
  [ -f "$root/config/api_key.php" ] || cp /usr/src/piclite-config/api_key.php "$root/config/api_key.php"
  [ -f "$root/config/config.guest.php" ] || cp /usr/src/piclite-config/config.guest.php "$root/config/config.guest.php"
  [ -f "$root/config/lite.local.example.php" ] || cp /usr/src/piclite-config/lite.local.example.php "$root/config/lite.local.example.php"
fi

if [ -f "$root/config/install.lock" ]; then
  rm -f "$root/config/install.token"
elif [ -n "${PICLITE_INSTALL_TOKEN:-}" ] || [ ! -f "$root/config/install.token" ]; then
  if [ -n "${PICLITE_INSTALL_TOKEN:-}" ]; then
    install_token="$PICLITE_INSTALL_TOKEN"
  else
    install_token="$(php -r 'echo bin2hex(random_bytes(16));')"
  fi
  printf '%s' "$install_token" > "$root/config/install.token"
  echo "PicLite install token: $install_token"
fi

php "$root/docker/hotlink-config.php"

for path in \
  "$root/config/lite-rate" \
  "$root/config/lite.secret.php" \
  "$root/config/lite.local.php" \
  "$root/config/lite.setup.php" \
  "$root/config/lite.tokens.php" \
  "$root/config/lite.tokens.lock"
do
  if [ -L "$path" ]; then
    echo "Refusing symlinked Lite security state: $path" >&2
    exit 1
  fi
done

for path in "$root/config/lite.tokens.php" "$root/config/lite.tokens.lock"
do
  if [ -e "$path" ] && [ ! -f "$path" ]; then
    echo "Refusing invalid Lite token state: $path" >&2
    exit 1
  fi
done

if [ ! -e "$root/config/lite.tokens.php" ]; then
  (umask 077; printf '%s\n' '<?php exit; ?>' '{"version":1,"tokens":[]}' > "$root/config/lite.tokens.php")
fi
if [ ! -e "$root/config/lite.tokens.lock" ]; then
  (umask 077; : > "$root/config/lite.tokens.lock")
fi

chown -R www-data:www-data "$root/i" "$root/config" "$root/admin/logs"

chmod 0755 "$root/i"
find "$root/admin/logs" -type d -exec chmod 0750 {} \;
find "$root/admin/logs" -type f -exec chmod 0640 {} \;
chmod 0750 "$root/config"
chmod 0700 "$root/config/lite-rate"
find "$root/config/lite-rate" -type f -exec chmod 0600 {} \;

for file in \
  "$root/config/lite.secret.php" \
  "$root/config/lite.local.php" \
  "$root/config/lite.setup.php" \
  "$root/config/lite.tokens.php" \
  "$root/config/lite.tokens.lock"
do
  if [ -f "$file" ]; then
    chmod 0600 "$file"
  fi
done

exec docker-php-entrypoint "$@"
