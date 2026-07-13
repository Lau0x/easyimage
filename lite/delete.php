<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$path = (string) ($_GET['path'] ?? '');
$expires = (int) ($_GET['expires'] ?? 0);
$signature = (string) ($_GET['signature'] ?? '');
$valid = LiteDeleteSignature::verify($path, $expires, $signature, (string) $liteConfig['hmac_secret'], (string) $liteConfig['public_path']);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$valid) {
        http_response_code(403);
        $message = '删除链接无效或已过期';
    } else {
        $message = (new LiteGallery($liteConfig))->delete($path) ? '图片已删除' : '图片不存在或已经删除';
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow"><title>删除图片 · PicLite Lite</title><link rel="stylesheet" href="<?= liteEscape(liteAppUrl($liteConfig, 'assets/app.css')) ?>"></head>
<body class="login-page"><main class="delete-shell"><section class="login-panel"><p class="eyebrow">PicLite Lite / 删除凭证</p><h1><?= $message !== '' ? liteEscape($message) : '删除这张图片？' ?></h1><?php if ($message === ''): ?><?php if ($valid): ?><p class="intro">此操作不可撤销。删除凭证将在 <?= liteEscape((new DateTimeImmutable('@' . $expires))->setTimezone(new DateTimeZone((string) $liteConfig['timezone']))->format('Y-m-d H:i:s')) ?> 失效。</p><form method="post"><button class="button primary" type="submit">确认删除</button></form><?php else: ?><p class="flash error" role="alert">删除链接无效或已过期</p><?php endif; ?><?php endif; ?><a class="back-link" href="<?= liteEscape(liteAppUrl($liteConfig)) ?>">返回工作台</a></section></main></body>
</html>
