<?php

declare(strict_types=1);

define('EASYIMAGE_ROOT', dirname(__DIR__));

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/UploadService.php';
require_once __DIR__ . '/src/Gallery.php';
require_once __DIR__ . '/src/TokenStore.php';

try {
    $liteConfig = LiteConfig::load(EASYIMAGE_ROOT);
} catch (RuntimeException $exception) {
    error_log('PicLite Lite configuration error: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PicLite Lite 配置错误，请检查服务器日志。';
    exit;
}
date_default_timezone_set((string) $liteConfig['timezone']);

if ($liteConfig['needs_setup']) {
    header('Location: ' . LiteUrl::app($liteConfig, 'setup.php'), true, 302);
    exit;
}

function liteEscape(string|int $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function liteRedirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function liteAppUrl(array $config, string $path = ''): string
{
    return LiteUrl::app($config, $path);
}
