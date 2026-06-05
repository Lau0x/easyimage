## PicLite

[![PHP](https://img.shields.io/badge/php-8.3%20docker-blue.svg)](https://www.php.net/)
[![Release](https://img.shields.io/github/v/release/Lau0x/piclite)](https://github.com/Lau0x/piclite/releases)
[![Docker](https://img.shields.io/badge/docker-ghcr.io%2Flau0x%2Fpiclite-blue)](https://github.com/Lau0x/piclite/pkgs/container/piclite)
[![License](https://img.shields.io/badge/license-GPL--2.0-yellowgreen.svg)](https://github.com/Lau0x/piclite/blob/main/LICENSE)

PicLite 是一个轻量、无数据库的自托管图床，适合个人、团队内网和小型公开站点使用。

本项目基于 [EasyImages2.0](https://github.com/icret/EasyImages2.0) 继续维护，保留原项目的无数据库结构和图片路径兼容性，同时补上更适合当前部署环境的 Docker、安全默认值和发布流程。

## 快速开始

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

## 功能

- 无数据库文件存储
- 多文件拖拽上传
- API Token 上传
- 图片广场和历史记录
- 缩略图、压缩、水印和格式转换
- 登录防爆破、上传限速和防盗链
- 上传日志、统计和公开查询
- PicGo、ShareX、uPic、浏览器插件上传

## 目录

- [安装图床](./安装图床.md)
- [Docker 部署](./三方安装指南.md)
- [安全配置](./安全配置.md)
- [API](./API.md)
- [后台管理](./后台管理.md)
- [常见问题](./常见问题.md)
- [更新日志](./update.md)
- [许可证](./许可证.md)

## 兼容说明

默认上传目录仍是 `/i/`，Docker 镜像是 `ghcr.io/lau0x/piclite`，Compose 服务名是 `piclite`。

## 开源许可

- 本项目使用 [GPL-2.0](https://github.com/Lau0x/piclite/blob/main/LICENSE)。
- PicLite 基于 [EasyImages2.0](https://github.com/icret/EasyImages2.0) 继续维护，感谢原作者 [Icret](https://github.com/icret) 和上游贡献者。
