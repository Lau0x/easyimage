<?php

/**
 * Program: EasyImage2.0
 * Author: Icret
 * Date: 2022/3/13 20:11
 * For: 源图保护解密
 */

require_once __DIR__ . '/function.php';

if (isset($_GET['key'])) {
    $hide_original = $_GET['key'];
    $real_path = easyimage_resolve_upload_file(urlHash($hide_original, 1, crc32($config['hide_key'])));
} else {
    $real_path = APP_ROOT . '/public/images/404.png';
}

// 文件不存在
if (!$real_path || !is_file($real_path)) {
    $real_path = APP_ROOT . '/public/images/404.png';
}

// 获取文件后缀
$ex = strtolower(pathinfo($real_path, PATHINFO_EXTENSION));
$mime = array(
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'jfif' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'bmp' => 'image/bmp',
    'webp' => 'image/webp',
    'ico' => 'image/x-icon',
    'svg' => 'image/svg+xml',
);

// 设置头
header("Content-Type: " . (isset($mime[$ex]) ? $mime[$ex] : 'application/octet-stream'));

//输出文件
echo file_get_contents($real_path);

exit;
