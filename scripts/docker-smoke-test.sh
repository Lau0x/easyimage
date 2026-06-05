#!/bin/sh
set -eu

IMAGE="${IMAGE:-easyimage:smoke}"
CONTAINERS=""

cleanup() {
  for container in $CONTAINERS; do
    docker stop "$container" >/dev/null 2>&1 || true
  done
}

trap cleanup EXIT INT TERM

fail() {
  echo "smoke test failed: $*" >&2
  exit 1
}

container_port() {
  docker port "$1" 80/tcp | sed -n '1s/.*://p'
}

wait_http() {
  url="$1"
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    code="$(curl -sS -o /dev/null -w '%{http_code}' "$url" 2>/dev/null || true)"
    [ "$code" = "200" ] && return 0
    sleep 1
  done
  fail "expected $url to become available"
}

assert_status() {
  expected="$1"
  url="$2"
  referer="${3:-}"
  if [ -n "$referer" ]; then
    code="$(curl -sS -o /dev/null -w '%{http_code}' -H "Referer: $referer" "$url")"
  else
    code="$(curl -sS -o /dev/null -w '%{http_code}' "$url")"
  fi
  [ "$code" = "$expected" ] || fail "expected $expected for $url, got $code"
}

add_test_files() {
  docker exec "$1" sh -c 'printf image > /var/www/html/i/test.png && printf "<?php echo 123; ?>" > /var/www/html/i/pwn.php'
}

docker build -t "$IMAGE" .

plain_container="$(docker run -d -p 127.0.0.1::80 "$IMAGE")"
CONTAINERS="$CONTAINERS $plain_container"
plain_port="$(container_port "$plain_container")"
wait_http "http://127.0.0.1:$plain_port/install/index.php"
docker exec "$plain_container" test -s /var/www/html/config/install.token
docker exec "$plain_container" test -d /var/www/html/admin/logs/login-rate
docker exec "$plain_container" test -d /var/www/html/admin/logs/upload-rate
add_test_files "$plain_container"
assert_status 403 "http://127.0.0.1:$plain_port/config/config.php"
assert_status 403 "http://127.0.0.1:$plain_port/i/pwn.php"
assert_status 200 "http://127.0.0.1:$plain_port/i/test.png" "https://bad.test/page"

hotlink_container="$(docker run -d -e EASYIMAGE_HOTLINK=1 -e EASYIMAGE_HOTLINK_DOMAINS=allowed.test -p 127.0.0.1::80 "$IMAGE")"
CONTAINERS="$CONTAINERS $hotlink_container"
hotlink_port="$(container_port "$hotlink_container")"
wait_http "http://127.0.0.1:$hotlink_port/install/index.php"
add_test_files "$hotlink_container"
assert_status 200 "http://127.0.0.1:$hotlink_port/i/test.png"
assert_status 200 "http://127.0.0.1:$hotlink_port/i/test.png" "https://allowed.test/page"
assert_status 200 "http://127.0.0.1:$hotlink_port/i/test.png" "https://img.allowed.test/page"
assert_status 403 "http://127.0.0.1:$hotlink_port/i/test.png" "https://bad.test/page"

strict_hotlink_container="$(docker run -d -e EASYIMAGE_HOTLINK=1 -e EASYIMAGE_HOTLINK_DOMAINS=allowed.test -e EASYIMAGE_HOTLINK_ALLOW_EMPTY=0 -p 127.0.0.1::80 "$IMAGE")"
CONTAINERS="$CONTAINERS $strict_hotlink_container"
strict_hotlink_port="$(container_port "$strict_hotlink_container")"
wait_http "http://127.0.0.1:$strict_hotlink_port/install/index.php"
add_test_files "$strict_hotlink_container"
assert_status 403 "http://127.0.0.1:$strict_hotlink_port/i/test.png"
assert_status 200 "http://127.0.0.1:$strict_hotlink_port/i/test.png" "https://allowed.test/page"

echo "Docker smoke tests passed"
