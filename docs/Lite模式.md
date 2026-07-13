# Lite 模式

Lite 是 PicLite 的简单工作台。它只依赖文件系统，继续把原图写入 `i/Y/m/d/`，因此可以和现有 EasyImages2.0 图片目录共存。

## 适合什么场景

- 个人或小团队自用图床
- 不想维护数据库，只需要上传、图库、删除和 API
- 需要保留现有 `/i/` 图片直链

Lite 不包含 Classic 的水印、压缩、远程下载、统计和多角色管理。需要这些功能时，把 `LITE_APP_PATH` 保持为 `/lite`，继续从原路径使用 Classic。

Lite 的上传和图库管理支持 JPEG（`.jpg` / `.jpeg`）、PNG、GIF 和 WebP。从 Classic 迁移的 BMP、ICO、JFIF、TIF 和 TGA 等其他格式仍保留在 `i/` 中，旧直链可继续访问，但不会出现在 Lite 图库中，也不能由 Lite 上传或管理。

## 启动与初始化

```sh
cp .env.example .env
docker compose pull
docker compose up -d
```

访问 `http://localhost:8080/lite/` 后，页面会跳转到首次初始化。初始化凭证只写入容器日志，有效期为 30 分钟：

```sh
docker compose logs piclite | grep 'PicLite Lite setup token'
```

完成后会生成 `config/lite.local.php`，管理员密码只保存为哈希；该文件以及 Lite 密钥、凭证存储文件均使用 `0600` 权限。

## 路径模式

| `LITE_APP_PATH` | 工作台地址 | 说明 |
| --- | --- | --- |
| `/lite` | `https://img.example.com/lite/` | 默认，Lite 与 Classic 共存 |
| `/` | `https://img.example.com/` | Lite 占用根路径，`/i/` 图片直链仍保留 |

`LITE_BASE_URL` 只接受带 `http://` 或 `https://` 的域名 origin，不要填写子路径。例如：

```dotenv
LITE_BASE_URL=https://img.example.com
LITE_APP_PATH=/
```

## 常用环境变量

| 变量 | 默认值 | 用途 |
| --- | --- | --- |
| `LITE_DISABLE_LEGACY_CONFIG` | `0`（程序默认） | 设为 `1` 后完全不继承 Classic 配置；旧 API Token 不会因兼容请求字段而自动生效 |
| `LITE_TIMEZONE` | `Asia/Shanghai` | 上传目录和图库日期使用该时区的自然日 |
| `LITE_MAX_FILE_SIZE` | `10485760` | 单张图片最大字节数 |
| `LITE_MAX_FILES` | `10` | 网页单次最多上传数量，最高 50 |
| `LITE_API_ENABLED` | `0`（程序默认） | 是否开放 Lite API |
| `LITE_TRUSTED_PROXY` | `0` | 是否信任反向代理传入的客户端 IP |
| `LITE_TRUSTED_PROXY_IPS` | 空 | 可信代理 IP，多个值用逗号分隔 |
| `LITE_CLIENT_IP_HEADER` | `HTTP_X_REAL_IP` | 读取客户端 IP 的请求头 |

只有确认请求必定经过自己的反向代理时，才启用 `LITE_TRUSTED_PROXY`，并填写明确的代理 IP 白名单。

程序在未设置 `LITE_DISABLE_LEGACY_CONFIG` 时默认为 `0`，会尝试继承 Classic 配置；面向独立 Lite 部署的 `.env.example` 推荐显式设为 `1`。程序在未设置 `LITE_API_ENABLED` 时默认关闭 API；`.env.example` 会显式设为 `1`，使初始化后可以直接使用受管凭证 API，不需要 API 时可改回 `0`。

## API 凭证

把 `LITE_API_ENABLED=1` 后重建容器，在“API 凭证”页面创建 30、90 或 365 天有效的凭证。原始凭证只显示一次，服务器只保存哈希；遗失后应吊销并重新创建。

```sh
curl -H 'Authorization: Bearer YOUR_TOKEN' \
  -F 'image=@photo.jpg' \
  https://img.example.com/lite/api.php
```

也可以使用 `X-API-Key` 请求头，或用表单字段 `token` 兼容旧上传工具。成功响应继续提供 `url`、`thumb` 和限时删除地址 `del`。

这里兼容的只是请求头、表单字段和响应字段，不会自动启用 EasyImages2.0 的旧 API Token。`allow_legacy_api_tokens` 默认为 `false`；从 Classic 迁移到 Lite 后，应在“API 凭证”页面创建新的受管凭证，并替换上传工具中的旧 Token。

## 备份

备份以下挂载目录即可完整保存图片和状态：

- `i/`
- `config/`
- `admin/logs/`

升级前至少备份 `i/` 和 `config/`，不要把真实 `.env`、`lite.local.php`、密钥或 API 凭证提交到 Git。
