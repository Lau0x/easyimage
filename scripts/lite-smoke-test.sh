#!/usr/bin/env bash

set -euo pipefail

image=${1:-piclite:ci}
container_name=${LITE_SMOKE_CONTAINER_NAME:-piclite-lite-smoke-$$-$RANDOM}
run_id="$$-$RANDOM-$RANDOM"
container_id=''
host_uid=$(id -u)
host_gid=$(id -g)

for required_command in docker curl python3; do
    if ! command -v "$required_command" >/dev/null 2>&1; then
        printf '[lite-smoke] FAIL: required command not found: %s\n' "$required_command" >&2
        exit 127
    fi
done

tmp_root=$(mktemp -d /tmp/piclite-lite-smoke.XXXXXX)
config_dir="$tmp_root/config"
image_dir="$tmp_root/i"
log_dir="$tmp_root/logs"
cookie_jar="$tmp_root/cookies.txt"
base_url=''

cleanup() {
    exit_status=$?
    trap - EXIT
    safe_to_clean=1
    if [[ -n $container_id ]]; then
        container_run_id=$(docker inspect --format '{{index .Config.Labels "com.piclite.lite-smoke"}}' "$container_id" 2>/dev/null || true)
        if [[ $container_run_id == "$run_id" ]]; then
            if ! docker rm --force "$container_id" >/dev/null 2>&1; then
                safe_to_clean=0
                printf '[lite-smoke] cleanup refused: owned container could not be stopped\n' >&2
            fi
        else
            safe_to_clean=0
            printf '[lite-smoke] cleanup refused: container ownership label mismatch\n' >&2
        fi
    fi

    if [[ $safe_to_clean == 1 && $tmp_root == /tmp/piclite-lite-smoke.* ]]; then
        docker run --rm --network none --entrypoint sh \
            --volume "$config_dir:/cleanup/config" \
            --volume "$image_dir:/cleanup/i" \
            --volume "$log_dir:/cleanup/logs" \
            "$image" -c '
                set -eu
                for directory in /cleanup/config /cleanup/i /cleanup/logs; do
                    find "$directory" -mindepth 1 -delete
                    chown "$1:$2" "$directory"
                    chmod 0700 "$directory"
                done
            ' sh "$host_uid" "$host_gid" >/dev/null 2>&1 || true
        if ! rm -rf "$tmp_root"; then
            printf '[lite-smoke] cleanup failed: temporary directory remains\n' >&2
            exit_status=1
        fi
    fi
    exit "$exit_status"
}

fail() {
    printf '[lite-smoke] FAIL: %s\n' "$1" >&2
    exit 1
}

pass() {
    printf '[lite-smoke] %s\n' "$1"
}

diagnose_container() {
    [[ -n $container_id ]] || return 0
    printf '[lite-smoke] container status:\n' >&2
    docker inspect --format 'status={{.State.Status}} exit_code={{.State.ExitCode}}' "$container_id" 2>&1 \
        | sed -E 's/[a-f0-9]{64}/[REDACTED]/g; s/eil_[A-Za-z0-9_-]+/[REDACTED]/g; s/CiSmoke-[0-9]+-[0-9]+-Password/[REDACTED]/g' >&2 || true
    printf '[lite-smoke] last 20 container log lines:\n' >&2
    docker logs --tail 20 "$container_id" 2>&1 \
        | sed -E 's/[a-f0-9]{64}/[REDACTED]/g; s/eil_[A-Za-z0-9_-]+/[REDACTED]/g; s/CiSmoke-[0-9]+-[0-9]+-Password/[REDACTED]/g' >&2 || true
}

extract_hidden() {
    local name=$1
    local file=$2
    sed -n "s/.*name=\"${name}\" value=\"\([^\"]*\)\".*/\1/p" "$file" | head -n 1
}

json_assert() {
    local file=$1
    local expression=$2
    python3 -c 'import json,sys; data=json.load(open(sys.argv[1], encoding="utf-8")); assert eval(sys.argv[2], {"__builtins__": {}}, {"data": data})' "$file" "$expression"
}

token_count() {
    docker exec "$container_id" php -r '
        $raw = file_get_contents("/var/www/html/config/lite.tokens.php");
        if ($raw === false) {
            exit(2);
        }
        $newline = strpos($raw, "\n");
        if ($newline === false) {
            exit(3);
        }
        $data = json_decode(substr($raw, $newline + 1), true, 512, JSON_THROW_ON_ERROR);
        if (!isset($data["tokens"]) || !is_array($data["tokens"])) {
            exit(4);
        }
        echo count($data["tokens"]);
    '
}

mkdir -p "$config_dir" "$image_dir" "$log_dir"
chmod 0700 "$config_dir" "$image_dir" "$log_dir"
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

python3 -c 'import base64,sys; open(sys.argv[1], "wb").write(base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII="))' "$tmp_root/pixel.png"
printf 'this is not an image\n' > "$tmp_root/not-an-image.png"

container_id=$(docker run --detach --name "$container_name" \
    --label "com.piclite.lite-smoke=$run_id" \
    --publish 127.0.0.1::80 \
    --env LITE_DISABLE_LEGACY_CONFIG=1 \
    --env LITE_API_ENABLED=1 \
    --env LITE_APP_PATH=/lite \
    --env TZ=Asia/Shanghai \
    --volume "$config_dir:/var/www/html/config" \
    --volume "$image_dir:/var/www/html/i" \
    --volume "$log_dir:/var/www/html/admin/logs" \
    "$image")
[[ $container_id =~ ^[a-f0-9]{64}$ ]] || fail 'Docker did not return a container id'

if [[ ${LITE_SMOKE_TEST_FAIL_AFTER_START:-0} == 1 ]]; then
    fail 'injected failure after container start'
fi

host_port=$(docker inspect --format '{{(index (index .NetworkSettings.Ports "80/tcp") 0).HostPort}}' "$container_id")
[[ $host_port =~ ^[0-9]+$ ]] || fail 'Docker did not allocate a host port'
base_url="http://127.0.0.1:$host_port"

ready=0
for _ in $(seq 1 60); do
    status=$(curl --silent --output /dev/null --write-out '%{http_code}' "$base_url/lite/setup.php" || true)
    if [[ $status == 200 ]]; then
        ready=1
        break
    fi
    sleep 0.5
done
if [[ $ready != 1 ]]; then
    diagnose_container
    fail 'Lite did not become ready'
fi
pass 'HTTP ready'

setup_token=''
for _ in $(seq 1 20); do
    setup_token=$(docker logs "$container_id" 2>&1 | sed -n 's/.*PicLite Lite setup token (expires in 30 minutes): \([a-f0-9]\{64\}\).*/\1/p' | tail -n 1 || true)
    [[ -n $setup_token ]] && break
    sleep 0.25
done
if [[ ! $setup_token =~ ^[a-f0-9]{64}$ ]]; then
    diagnose_container
    fail 'Setup token was not written to the container log'
fi

if ! curl --silent --show-error --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/lite/setup.php" --output "$tmp_root/setup.html"; then
    diagnose_container
    fail 'Setup page request failed'
fi
setup_csrf=$(extract_hidden csrf "$tmp_root/setup.html")
if [[ ! $setup_csrf =~ ^[a-f0-9]{64}$ ]]; then
    diagnose_container
    fail 'Setup CSRF token missing'
fi

admin_user=ci-admin
admin_password="CiSmoke-$RANDOM-$RANDOM-Password"
if ! setup_status=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST "$base_url/lite/setup.php" \
    --data-urlencode "csrf=$setup_csrf" \
    --data-urlencode "setup_token=$setup_token" \
    --data-urlencode "username=$admin_user" \
    --data-urlencode "password=$admin_password" \
    --data-urlencode "password_confirmation=$admin_password"); then
    diagnose_container
    fail 'Setup request failed'
fi
if [[ $setup_status != 303 ]]; then
    diagnose_container
    fail 'Setup did not redirect after initialization'
fi
unset setup_token setup_csrf
pass 'one-time setup completed'

curl --silent --show-error --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/lite/" --output "$tmp_root/login.html"
login_csrf=$(extract_hidden csrf "$tmp_root/login.html")
[[ $login_csrf =~ ^[a-f0-9]{64}$ ]] || fail 'Login CSRF token missing'
login_status=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST "$base_url/lite/" \
    --data-urlencode 'action=login' \
    --data-urlencode "csrf=$login_csrf" \
    --data-urlencode "username=$admin_user" \
    --data-urlencode "password=$admin_password")
[[ $login_status == 303 ]] || fail 'Login did not redirect'
unset admin_password login_csrf
pass 'administrator login completed'

curl --silent --show-error --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    "$base_url/lite/tokens.php" --output "$tmp_root/tokens.html"
token_csrf=$(extract_hidden csrf "$tmp_root/tokens.html")
create_nonce=$(extract_hidden create_nonce "$tmp_root/tokens.html")
[[ $token_csrf =~ ^[a-f0-9]{64}$ ]] || fail 'Token CSRF token missing'
[[ $create_nonce =~ ^[a-f0-9]{64}$ ]] || fail 'Token create nonce missing'

create_status=$(curl --silent --show-error --output "$tmp_root/token-created.html" --write-out '%{http_code}' \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST "$base_url/lite/tokens.php" \
    --data-urlencode 'action=create' \
    --data-urlencode "csrf=$token_csrf" \
    --data-urlencode "create_nonce=$create_nonce" \
    --data-urlencode 'label=CI smoke token' \
    --data-urlencode 'days=30')
[[ $create_status == 200 ]] || fail 'Managed token creation failed'
api_token=$(sed -n 's/.*<code>\(eil_[A-Za-z0-9_-]\{43\}\)<\/code>.*/\1/p' "$tmp_root/token-created.html" | head -n 1)
token_id=$(sed -n 's/.*<small>\([a-f0-9]\{16\}\)<\/small>.*/\1/p' "$tmp_root/token-created.html" | head -n 1)
[[ $api_token =~ ^eil_[A-Za-z0-9_-]{43}$ ]] || fail 'Raw managed token missing from its one-time response'
[[ $token_id =~ ^[a-f0-9]{16}$ ]] || fail 'Managed token id missing'
[[ $(token_count) == 1 ]] || fail 'Unexpected managed token count after creation'

replay_status=$(curl --silent --show-error --output "$tmp_root/token-replay.html" --write-out '%{http_code}' \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST "$base_url/lite/tokens.php" \
    --data-urlencode 'action=create' \
    --data-urlencode "csrf=$token_csrf" \
    --data-urlencode "create_nonce=$create_nonce" \
    --data-urlencode 'label=replayed token request' \
    --data-urlencode 'days=30')
[[ $replay_status == 200 ]] || fail 'Replayed create nonce returned an unexpected HTTP status'
[[ $(token_count) == 1 ]] || fail 'Replayed create nonce created another token'
grep -q '创建请求已使用或失效' "$tmp_root/token-replay.html" || fail 'Replayed create nonce was not explicitly rejected'
if grep -F -- "$api_token" "$tmp_root/token-replay.html" >/dev/null; then
    fail 'Raw managed token was shown again after its one-time response'
fi
pass 'managed token is shown only once'

upload_status=$(curl --silent --show-error --output "$tmp_root/upload.json" --write-out '%{http_code}' \
    --header "X-API-Key: $api_token" \
    --form "image=@$tmp_root/pixel.png;type=image/png" \
    "$base_url/lite/api.php")
[[ $upload_status == 200 ]] || fail 'PNG API upload failed'
json_assert "$tmp_root/upload.json" 'data["result"] == "success" and data["code"] == 200 and data["srcName"] == "pixel.png" and data["message"] == "success" and data["url"] != "" and data["thumb"] == data["url"] and data["del"] != "" and data["id"] != ""'
image_url=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["url"])' "$tmp_root/upload.json")
delete_url=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["del"])' "$tmp_root/upload.json")
[[ $image_url == /i/* ]] || fail 'Upload returned a non-compatible image path'
[[ $delete_url == /lite/delete.php\?* ]] || fail 'Upload returned a non-compatible delete path'
[[ $(curl --silent --output /dev/null --write-out '%{http_code}' "$base_url$image_url") == 200 ]] || fail 'Uploaded image is not publicly readable'

bad_status=$(curl --silent --show-error --output "$tmp_root/bad-upload.json" --write-out '%{http_code}' \
    --header "X-API-Key: $api_token" \
    --form "image=@$tmp_root/not-an-image.png;type=image/png" \
    "$base_url/lite/api.php")
[[ $bad_status == 400 ]] || fail 'Invalid image MIME was not rejected'
json_assert "$tmp_root/bad-upload.json" 'data["result"] == "failed" and data["code"] == 400'

delete_status=$(curl --silent --show-error --output "$tmp_root/delete.html" --write-out '%{http_code}' \
    --request POST "$base_url$delete_url")
[[ $delete_status == 200 ]] || fail 'HMAC deletion failed'
grep -q '图片已删除' "$tmp_root/delete.html" || fail 'HMAC deletion was not confirmed'
[[ $(curl --silent --output /dev/null --write-out '%{http_code}' "$base_url$image_url") == 404 ]] || fail 'Deleted image is still available'
pass 'API upload, compatibility fields, MIME rejection, and HMAC deletion passed'

revoke_status=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    --cookie "$cookie_jar" --cookie-jar "$cookie_jar" \
    --request POST "$base_url/lite/tokens.php" \
    --data-urlencode 'action=revoke' \
    --data-urlencode "csrf=$token_csrf" \
    --data-urlencode "id=$token_id")
[[ $revoke_status == 303 ]] || fail 'Managed token revocation failed'

revoked_status=$(curl --silent --show-error --output "$tmp_root/revoked.json" --write-out '%{http_code}' \
    --header "X-API-Key: $api_token" \
    --form "image=@$tmp_root/pixel.png;type=image/png" \
    "$base_url/lite/api.php")
[[ $revoked_status == 401 ]] || fail 'Revoked managed token was not rejected'
json_assert "$tmp_root/revoked.json" 'data["result"] == "failed" and data["code"] == 202'
if docker exec "$container_id" sh -c 'grep -R -F -- "$1" /var/lib/php/sessions /var/www/html/config /var/www/html/admin/logs 2>/dev/null' sh "$api_token" >/dev/null; then
    fail 'Raw managed token persisted in session, token store, or application log'
fi
if docker logs "$container_id" 2>&1 | grep -F -- "$api_token" >/dev/null; then
    fail 'Raw managed token appeared in the container log'
fi
unset api_token token_csrf create_nonce
pass 'managed token revocation and non-persistence passed'

for state_file in lite.local.php lite.secret.php lite.tokens.php lite.tokens.lock; do
    [[ $(docker exec "$container_id" stat -c '%a' "/var/www/html/config/$state_file") == 600 ]] \
        || fail "$state_file permissions are not 0600"
done
pass 'security state permissions are 0600'
pass 'all checks passed'
