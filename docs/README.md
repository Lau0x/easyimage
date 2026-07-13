# PicLite

PicLite 是一个轻量、无数据库的自托管图床。图片、配置和日志都保存在文件系统中，Docker 镜像支持 `linux/amd64` 和 `linux/arm64`。

## 两种界面

- [Lite 模式](Lite模式.md)：简单工作台，提供上传、按日图库、删除和可吊销的 API 凭证
- [Classic 模式](安装图床.md)：保留水印、压缩、远程下载、统计等完整功能

两种模式共用 `i/` 图片目录。Lite 继续写入 `i/Y/m/d/`，原有图片直链无需改动。

## Docker 快速开始

```sh
git clone https://github.com/Lau0x/piclite.git
cd piclite
cp .env.example .env
docker compose pull
docker compose up -d
```

访问 `http://localhost:8080/lite/`，再从日志中取得首次初始化凭证：

```sh
docker compose logs piclite | grep 'PicLite Lite setup token'
```

![PicLite Lite 桌面工作台](images/lite-workspace-desktop.png)

已有 EasyImages2.0 数据时，请先阅读[迁移与回退](从EasyImages2.0迁移.md)，不要在验证前删除旧容器、旧镜像或备份。

## 常用入口

- [Lite 模式配置](Lite模式.md)
- [从 EasyImages2.0 迁移与回退](从EasyImages2.0迁移.md)
- [安全配置](安全配置.md)
- [Classic API](API.md)
- [常见问题](常见问题.md)

PicLite 使用 [GPL-2.0](许可证.md)，项目基于 [EasyImages2.0](https://github.com/icret/EasyImages2.0) 继续维护。
