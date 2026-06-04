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

header('Cache-Control: public, max-age=' . ((int)$config['cache_freq'] * 60 * 60));
Thumb::show($source, $width, $height);
