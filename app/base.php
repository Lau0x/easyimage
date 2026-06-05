<?php

/**
 * @author   icret
 * @project  PicLite
 * @Github   https://github.com/Lau0x/easyimage
 * @upstream https://github.com/icret/EasyImages2.0
 * @Last     2024-01-20

 * 上传服务器后第一次打开会检查运行环境，请根据提示操作；
 * 检查环境仅会在第一开始开始出现，并在config目录下生成EasyImage.lock文件，如需再次查看请删除此文件。

 * 本程序为开源程序，请遵守 GPL-2.0 许可证并保留必要的上游来源信息。
 */

/*---------------基础配置开始-------------------*/

// 设置html为utf8
header('Content-Type:text/html;charset=utf-8');
// 定义根目录
define('APP_ROOT', str_replace(DIRECTORY_SEPARATOR, '/', realpath(dirname(__FILE__) . '/../')));
// 时区设置 https://www.php.net/manual/zh/timezones.php
require_once APP_ROOT . '/config/config.php';
empty($config['timezone']) ? date_default_timezone_set('Asia/Shanghai') : date_default_timezone_set($config['timezone']);
// 修改内存限制 根据服务器配置选择，低于128M容易出现上传失败，你懂得图片挺占用内存的
ini_set('memory_limit', '512M');
// 判断当前系统是否为windows
define('IS_WIN', strstr(PHP_OS, 'WIN') ? 1 : 0);
// 定义程序版本
define('APP_VERSION', '2.8.7');

/*---------------基础配置结束-------------------*/
