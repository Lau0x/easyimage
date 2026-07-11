#!/bin/sh
set -eu

IMAGE="${IMAGE:-piclite:smoke}"
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
  docker exec "$1" php -r '$image = imagecreatetruecolor(20, 20); imagepng($image, "/var/www/html/i/test.png"); imagedestroy($image); file_put_contents("/var/www/html/i/pwn.php", "<?php echo 123; ?>");'
}

assert_config_filter() {
  docker exec "$1" php -r 'require "/var/www/html/app/function.php"; $post = array("title" => "ok", "maxPixels" => "40000000", "csrf_token" => "secret", "admin_form" => "", "password" => "plain", "unknown_key" => "bad", "domain" => array("bad"), "update" => "2026-06-05 10:30:00", "public_list" => array("time", "evil", "file", "time")); $filtered = easyimage_filter_config_update_post($post); if (!isset($filtered["title"]) || $filtered["title"] !== "ok" || $filtered["maxPixels"] !== "40000000") exit(1); foreach (array("csrf_token", "admin_form", "password", "unknown_key", "domain") as $key) { if (isset($filtered[$key])) exit(2); } if ($filtered["public_list"] !== array("time", "file")) exit(3); if ($filtered["update"] !== "2026-06-05 10:30:00") exit(4);'
}

assert_upload_validation() {
  docker exec "$1" php -r 'require "/var/www/html/app/function.php"; $image = imagecreatetruecolor(10, 10); imagepng($image, "/tmp/piclite-valid.png"); imagedestroy($image); $config["maxPixels"] = 100; if (!easyimage_validate_upload_image("/tmp/piclite-valid.png")["valid"]) exit(1); $config["maxPixels"] = 99; if (easyimage_validate_upload_image("/tmp/piclite-valid.png")["valid"]) exit(2); file_put_contents("/tmp/piclite-invalid.png", "not an image"); if (easyimage_validate_upload_image("/tmp/piclite-invalid.png")["valid"]) exit(3);'
}

assert_atomic_write() {
  docker exec "$1" php -r 'require "/var/www/html/app/function.php"; $file = "/tmp/piclite-atomic.php"; if (!writefile($file, "<?php return 42;")) exit(1); $value = include $file; if ($value !== 42) exit(2);'
}

assert_thumb_cache() {
  container="$1"
  base_url="$2"
  assert_status 200 "$base_url/app/thumb.php?img=/i/test.png"
  count="$(docker exec "$container" sh -c 'find /var/www/html/i/cache/thumbs -type f ! -name "*.lock" | wc -l')"
  [ "$count" = "1" ] || fail "expected one generated thumbnail, got $count"
  assert_status 200 "$base_url/app/thumb.php?img=/i/test.png"
  count="$(docker exec "$container" sh -c 'find /var/www/html/i/cache/thumbs -type f ! -name "*.lock" | wc -l')"
  [ "$count" = "1" ] || fail "expected thumbnail cache reuse, got $count files"
}

assert_chunk_upload() {
  container="$1"
  port="$2"
  upload_id="0123456789abcdef0123456789abcdef"

  docker exec "$container" php -r 'require "/var/www/html/app/function.php"; $config["chunks"] = 1024; if (!cache_write("/var/www/html/config/config.php", $config)) exit(1);'
  docker restart "$container" >/dev/null
  port="$(container_port "$container")"
  wait_http "http://127.0.0.1:$port/install/index.php"
  docker exec "$container" php -r '$image = imagecreatetruecolor(40, 40); imagepng($image, "/tmp/chunk-source.png"); imagedestroy($image);'
  docker exec "$container" split -n 2 /tmp/chunk-source.png /tmp/chunk-

  first="$(docker exec "$container" sh -c "curl -sS -F file=@/tmp/chunk-aa -F name=chunk-test.png -F chunk=0 -F chunks=2 -F upload_id=$upload_id -F sign=\$(date +%s) http://127.0.0.1/app/upload.php")"
  case "$first" in
    *'"code":206'*) ;;
    *) fail "expected first chunk to be accepted, got $first" ;;
  esac

  second="$(docker exec "$container" sh -c "curl -sS -F file=@/tmp/chunk-ab -F name=chunk-test.png -F chunk=1 -F chunks=2 -F upload_id=$upload_id -F sign=\$(date +%s) http://127.0.0.1/app/upload.php")"
  case "$second" in
    *'"code":200'*) ;;
    *) fail "expected assembled chunk upload to succeed, got $second" ;;
  esac
}

docker build -t "$IMAGE" .

plain_container="$(docker run -d -p 127.0.0.1::80 "$IMAGE")"
CONTAINERS="$CONTAINERS $plain_container"
plain_port="$(container_port "$plain_container")"
wait_http "http://127.0.0.1:$plain_port/install/index.php"
docker exec "$plain_container" test -s /var/www/html/config/install.token
docker exec "$plain_container" test -d /var/www/html/admin/logs/login-rate
docker exec "$plain_container" test -d /var/www/html/admin/logs/upload-rate
assert_config_filter "$plain_container"
assert_upload_validation "$plain_container"
assert_atomic_write "$plain_container"
add_test_files "$plain_container"
assert_status 403 "http://127.0.0.1:$plain_port/config/config.php"
assert_status 403 "http://127.0.0.1:$plain_port/i/pwn.php"
assert_status 200 "http://127.0.0.1:$plain_port/i/test.png" "https://bad.test/page"
assert_thumb_cache "$plain_container" "http://127.0.0.1:$plain_port"
assert_chunk_upload "$plain_container" "$plain_port"

hotlink_container="$(docker run -d -e PICLITE_HOTLINK=1 -e PICLITE_HOTLINK_DOMAINS=allowed.test -p 127.0.0.1::80 "$IMAGE")"
CONTAINERS="$CONTAINERS $hotlink_container"
hotlink_port="$(container_port "$hotlink_container")"
wait_http "http://127.0.0.1:$hotlink_port/install/index.php"
add_test_files "$hotlink_container"
assert_status 200 "http://127.0.0.1:$hotlink_port/i/test.png"
assert_status 200 "http://127.0.0.1:$hotlink_port/i/test.png" "https://allowed.test/page"
assert_status 200 "http://127.0.0.1:$hotlink_port/i/test.png" "https://img.allowed.test/page"
assert_status 403 "http://127.0.0.1:$hotlink_port/i/test.png" "https://bad.test/page"

strict_hotlink_container="$(docker run -d -e PICLITE_HOTLINK=1 -e PICLITE_HOTLINK_DOMAINS=allowed.test -e PICLITE_HOTLINK_ALLOW_EMPTY=0 -p 127.0.0.1::80 "$IMAGE")"
CONTAINERS="$CONTAINERS $strict_hotlink_container"
strict_hotlink_port="$(container_port "$strict_hotlink_container")"
wait_http "http://127.0.0.1:$strict_hotlink_port/install/index.php"
add_test_files "$strict_hotlink_container"
assert_status 403 "http://127.0.0.1:$strict_hotlink_port/i/test.png"
assert_status 200 "http://127.0.0.1:$strict_hotlink_port/i/test.png" "https://allowed.test/page"

echo "Docker smoke tests passed"
