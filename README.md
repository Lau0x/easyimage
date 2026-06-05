## PicLite

[![PHP](https://img.shields.io/badge/php-8.3%20docker-blue.svg)](https://www.php.net/)
[![Release](https://img.shields.io/github/v/release/Lau0x/piclite)](https://github.com/Lau0x/piclite/releases)
[![Docker](https://img.shields.io/badge/docker-ghcr.io%2Flau0x%2Fpiclite-blue)](https://github.com/Lau0x/piclite/pkgs/container/piclite)
[![License](https://img.shields.io/badge/license-GPL--2.0-yellowgreen.svg)](https://github.com/Lau0x/piclite/blob/main/LICENSE)

PicLite 是一个轻量、无数据库的自托管图床，适合个人、团队内网和小型公开站点使用。

本项目基于 [EasyImages2.0](https://github.com/icret/EasyImages2.0) 继续维护，保留原项目的无数据库结构和图片路径兼容性，同时补上更适合当前部署环境的 Docker、安全默认值和发布流程。

## 现在的重点

- 无数据库：图片、配置和日志都在文件系统里，迁移时直接备份目录。
- Docker 优先：提供 `linux/amd64` 和 `linux/arm64` 预构建镜像。
- 默认安全：安装 Token、后台登录防爆破、上传目录脚本阻断、配置目录访问阻断。
- 可选防护：防盗链、上传限速、游客每日上传限制、IP 黑白名单。
- 常用输出：直链、Markdown、BBCode、HTML、缩略图。
- 兼容生态：保留 PicGo、ShareX、uPic、浏览器插件等上传方式。

## Docker 快速部署

使用仓库内的 `docker-compose.yml`：

```sh
git clone https://github.com/Lau0x/piclite.git
cd piclite
docker compose pull
docker compose up -d
```

启动后访问 `http://localhost:8080/install` 完成安装。首次安装需要填写安装 Token，可用下面命令查看：

```sh
docker compose logs piclite
```

默认镜像是 `ghcr.io/lau0x/piclite:latest`，Compose 服务名是 `piclite`。

也可以不 clone 仓库，直接运行镜像：

```sh
mkdir -p piclite/i piclite/config piclite/admin/logs
cd piclite
docker run -d --name piclite -p 8080:80 \
  -e TZ=Asia/Shanghai \
  -v "$PWD/i:/var/www/html/i" \
  -v "$PWD/config:/var/www/html/config" \
  -v "$PWD/admin/logs:/var/www/html/admin/logs" \
  ghcr.io/lau0x/piclite:latest
```

需要迁移时，备份这三个目录即可：

- `i/`
- `config/`
- `admin/logs/`

## 可选防盗链

```yaml
environment:
  TZ: Asia/Shanghai
  PICLITE_HOTLINK: "1"
  PICLITE_HOTLINK_DOMAINS: "example.com,www.example.com"
  PICLITE_HOTLINK_ALLOW_EMPTY: "1"
```

`PICLITE_HOTLINK` 默认关闭。开启后只允许配置域名引用 `i/` 目录下的图片；`PICLITE_HOTLINK_ALLOW_EMPTY` 为 `1` 时允许直接打开图片或无来源客户端访问。

## 裸机部署

推荐使用 Docker。裸机部署请参考：

- [安装图床](./docs/安装图床.md)
- [安全配置](./docs/安全配置.md)
- [API](./docs/API.md)
- [常见问题](./docs/常见问题.md)

## 常用功能

- 多文件拖拽上传
- 图片广场和历史记录
- API Token 上传
- 管理员和上传者账号
- 自定义上传目录
- 缩略图生成
- 图片压缩和格式转换
- 文字/图片水印
- 图片鉴黄接口
- 上传日志和统计
- 加密删除链接

## 路径说明

- 默认上传目录仍是 `/i/`
- Docker 镜像是 `ghcr.io/lau0x/piclite`
- Compose 服务名是 `piclite`
- 部分内部函数和旧静态文件名仍包含 `easyimage`，这是代码实现细节

## 开源许可

- 本项目使用 [GPL-2.0](https://github.com/Lau0x/piclite/blob/main/LICENSE)。
- PicLite 基于 [EasyImages2.0](https://github.com/icret/EasyImages2.0) 继续维护，感谢原作者 [Icret](https://github.com/icret) 和上游贡献者。
