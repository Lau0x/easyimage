<?php

require_once __DIR__ . '/function.php';
require_once __DIR__ . '/class.thumb.php';

function easyimage_thumb_not_found()
{
    $file = APP_ROOT . '/public/images/404.png';
    header('Content-Type: image/png');
    readfile($file);
    exit;
}

function easyimage_thumb_passthrough($file, $mime)
{
    global $config;

    $cacheAge = (int)$config['cache_freq'] * 60 * 60;
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=' . $cacheAge);
    readfile($file);
    exit;
}

function easyimage_thumb_output($file, $mime, $cacheAge)
{
    $etag = '"' . hash('sha256', filemtime($file) . '|' . filesize($file)) . '"';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=' . $cacheAge . ', immutable');
    header('ETag: ' . $etag);

    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }

    readfile($file);
    exit;
}

if (empty($_GET['img'])) {
    easyimage_thumb_not_found();
}

$source = easyimage_resolve_upload_file($_GET['img']);
if (!$source) {
    easyimage_thumb_not_found();
}

$extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
$passthroughTypes = array(
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
);

if (isset($passthroughTypes[$extension])) {
    easyimage_thumb_passthrough($source, $passthroughTypes[$extension]);
}

if (($extension === 'gif' && isGifAnimated($source)) || ($extension === 'webp' && isWebpAnimated($source))) {
    easyimage_thumb_passthrough($source, 'image/' . $extension);
}

$gdType = in_array($extension, array('jpg', 'jpeg', 'jfif')) ? 'jpeg' : $extension;
if ($gdType === '') {
    easyimage_thumb_not_found();
}
$createFunction = 'imagecreatefrom' . $gdType;
if (!function_exists($createFunction)) {
    easyimage_thumb_passthrough($source, 'image/' . $gdType);
}

$width = max(1, (int)$config['thumbnail_w']);
$height = max(1, (int)$config['thumbnail_h']);
$cacheHours = isset($config['cache_freq']) ? (int)$config['cache_freq'] : 2;
$cacheAge = max(1, $cacheHours) * 60 * 60;
$cacheDir = APP_ROOT . $config['path'] . 'cache/thumbs/';
$cacheKey = hash('sha256', $source . '|' . filemtime($source) . '|' . $width . '|' . $height);
$cacheFile = $cacheDir . $cacheKey . '.' . $gdType;
$mimeTypes = array(
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'bmp' => 'image/bmp',
    'webp' => 'image/webp',
);
$mime = isset($mimeTypes[$gdType]) ? $mimeTypes[$gdType] : 'application/octet-stream';

if (is_file($cacheFile) && filesize($cacheFile) > 0) {
    easyimage_thumb_output($cacheFile, $mime, $cacheAge);
}

if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
    easyimage_thumb_passthrough($source, $mime);
}

$lockFile = $cacheFile . '.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX)) {
    if (is_resource($lock)) fclose($lock);
    easyimage_thumb_passthrough($source, $mime);
}

if (!is_file($cacheFile) || filesize($cacheFile) === 0) {
    $tempFile = $cacheFile . '.tmp-' . bin2hex(easyimage_random_bytes(6));
    try {
        Thumb::out($source, $tempFile, $width, $height);
        if (is_file($tempFile) && filesize($tempFile) > 0) {
            @rename($tempFile, $cacheFile);
        } else {
            @unlink($tempFile);
        }
    } catch (Throwable $e) {
        @unlink($tempFile);
    }
}

flock($lock, LOCK_UN);
fclose($lock);
@unlink($lockFile);

if (is_file($cacheFile) && filesize($cacheFile) > 0) {
    easyimage_thumb_output($cacheFile, $mime, $cacheAge);
}

easyimage_thumb_passthrough($source, $mime);
