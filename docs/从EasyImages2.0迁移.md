# 从 EasyImages2.0 迁移与回退

PicLite Lite 不使用数据库，并继续使用 `i/Y/m/d/`。只要新容器挂载原来的 `i/` 目录，旧图片 URL 就不需要改。

以下路径仅作示例，请替换成服务器上的真实目录。

## 1. 记录现状并备份

先记录旧容器实际使用的镜像 ID、镜像标签和仓库 digest：

```sh
cd /srv/easyimage
umask 077
MIGRATION_TS="$(date +%Y%m%d-%H%M%S)"
BACKUP="$HOME/easyimage-before-piclite-${MIGRATION_TS}.tgz"
IMAGE_RECORD="$HOME/easyimage-before-piclite-${MIGRATION_TS}.images.txt"

OLD_CONTAINER_ID="$(docker compose -f /srv/easyimage/docker-compose.yml ps -q easyimage)"
test -n "$OLD_CONTAINER_ID"
docker inspect --format='old_image_ref={{.Config.Image}} old_image_id={{.Image}}' "$OLD_CONTAINER_ID" | tee "$IMAGE_RECORD"
OLD_IMAGE_ID="$(docker inspect --format='{{.Image}}' "$OLD_CONTAINER_ID")"
docker image inspect --format='old_image_id={{.Id}} old_repo_digests={{json .RepoDigests}}' "$OLD_IMAGE_ID" | tee -a "$IMAGE_RECORD"
chmod 600 "$IMAGE_RECORD"
```

再创建带时间戳的备份。以下命令包含三个持久化目录、Compose 文件和存在时的 `.env` 与 Compose override：

```sh
cd /srv/easyimage
umask 077
set -- i config admin/logs docker-compose.yml
[ ! -f .env ] || set -- "$@" .env
[ ! -f docker-compose.override.yml ] || set -- "$@" docker-compose.override.yml
tar -czf "$BACKUP" "$@"

tar -tzf "$BACKUP" | sed -n '1,80p'
tar -tzf "$BACKUP" | grep -Fqx 'i/'
tar -tzf "$BACKUP" | grep -Fqx 'config/'
tar -tzf "$BACKUP" | grep -Fqx 'admin/logs/'
tar -tzf "$BACKUP" | grep -Fqx 'docker-compose.yml'
[ ! -f .env ] || tar -tzf "$BACKUP" | grep -Fqx '.env'
test "$(stat -c '%a' "$BACKUP")" = '600'
test "$(stat -c '%a' "$IMAGE_RECORD")" = '600'
ls -l "$BACKUP" "$IMAGE_RECORD"
```

如果 Nginx、Caddy 或其他部署配置也由该目录管理，请在 `tar` 前将它们的实际相对路径加入 `set --` 列表。备份包含管理员配置、API 凭证、`.env` 等敏感信息，不要上传到公开位置或放宽文件权限。只有在上述列表和 `0600` 权限检查全部通过后，才可停止旧容器。

不要删除旧 Compose 文件、旧镜像或备份。迁移验证完成前，它们就是回退入口。

## 2. 让 PicLite 挂载原目录

在 `/srv/piclite/docker-compose.yml` 中使用原数据目录的绝对路径：

```yaml
services:
  piclite:
    image: ghcr.io/lau0x/piclite:v0.3.0
    ports:
      - "8080:80"
    environment:
      TZ: Asia/Shanghai
      LITE_DISABLE_LEGACY_CONFIG: "1"
      LITE_APP_PATH: "/lite"
      LITE_BASE_URL: "https://img.example.com"
      LITE_TIMEZONE: Asia/Shanghai
      LITE_API_ENABLED: "1"
    volumes:
      - /srv/easyimage/i:/var/www/html/i
      - /srv/easyimage/config:/var/www/html/config
      - /srv/easyimage/admin/logs:/var/www/html/admin/logs
    restart: unless-stopped
```

生产迁移不要使用 `latest`。升级后续版本时也应显式更换版本标签，或在验证后改为对应的 `@sha256:...` digest。

首次切换建议使用 `/lite`。确认旧链接、上传和回退都正常后，再根据需要改为 `/`。

## 3. 切换容器

先拉取固定版本，并将新镜像的实际 ID 和 digest 追加到迁移记录。确认旧、新镜像都已记录后再切换：

```sh
docker compose -f /srv/piclite/docker-compose.yml pull piclite
docker image inspect --format='new_image_id={{.Id}} new_repo_digests={{json .RepoDigests}}' ghcr.io/lau0x/piclite:v0.3.0 | tee -a "$IMAGE_RECORD"
chmod 600 "$IMAGE_RECORD"
cat "$IMAGE_RECORD"

docker compose -f /srv/easyimage/docker-compose.yml down
docker compose -f /srv/piclite/docker-compose.yml up -d
```

打开 Lite 页面后，从日志取得初始化凭证并创建管理员：

```sh
docker compose -f /srv/piclite/docker-compose.yml logs piclite | grep 'PicLite Lite setup token'
```

Lite 不会自动接管 EasyImages2.0 的旧 API Token。即使保留了兼容的 `X-API-Key` 请求头和 `token` 表单字段，`allow_legacy_api_tokens` 仍默认为 `false`。如果需要 API，请登录 Lite 后在“API 凭证”页面创建新的受管凭证，再把旧上传工具切换到新 Token。不需要 API 时，可将上面的 `LITE_API_ENABLED` 改为 `"0"`。

Lite 的上传和图库管理范围为 JPEG（`.jpg` / `.jpeg`）、PNG、GIF 和 WebP。Classic 中已有的 BMP、ICO、JFIF、TIF 和 TGA 等其他格式不会被转换或删除，旧直链仍可访问，但不会出现在 Lite 图库中，也不能由 Lite 上传或管理。

## 4. 验证清单

- 随机抽查几条迁移前的 `/i/YYYY/MM/DD/file.ext` 链接，包括 Classic 专有格式，确认旧直链 HTTP 200
- 登录 Lite，确认原有 JPEG、PNG、GIF 和 WebP 能按原日期显示；BMP、ICO、JFIF、TIF、TGA 等格式不应出现在 Lite 图库中
- 通过 Lite 上传一张 JPEG、PNG、GIF 或 WebP 测试图，确认目录使用 `Asia/Shanghai` 自然日
- 重启 PicLite，确认登录配置和图片仍存在
- 如需 API，先确认 EasyImages2.0 旧 Token 被拒绝；再创建新的 Lite 临时受管凭证完成一次上传，吊销后确认新 Token 无法继续使用
- 检查反向代理、HTTPS、上传大小限制和域名生成结果

验证期间不要删除原图，也不要用同名文件覆盖旧图片。

## 回退

正常回退不需要恢复备份。Lite 新增的 `config/lite.*` 文件会被旧应用忽略，新图片也仍在兼容的 `/i/` 路径中：

```sh
docker compose -f /srv/piclite/docker-compose.yml down
docker compose -f /srv/easyimage/docker-compose.yml up -d
```

随后重新抽查旧图片直链和一次上传。只有数据或配置确实损坏时才解压备份；如果迁移后已经有新图片，恢复前先单独保存当前 `i/`，避免覆盖新上传内容。
