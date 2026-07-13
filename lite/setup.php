<?php

declare(strict_types=1);

define('EASYIMAGE_ROOT', dirname(__DIR__));

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Setup.php';

try {
    $liteConfig = LiteConfig::load(EASYIMAGE_ROOT, false);
    if (!$liteConfig['needs_setup']) {
        header('Location: ' . LiteUrl::app($liteConfig), true, 302);
        exit;
    }
    date_default_timezone_set((string) $liteConfig['timezone']);
    LiteAuth::start($liteConfig);
    $setupState = LiteSetup::ensureToken(EASYIMAGE_ROOT);
} catch (RuntimeException $exception) {
    error_log('PicLite Lite setup error: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PicLite Lite 配置错误，请检查服务器日志。';
    exit;
}

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rateLimiter = new LiteLoginRateLimiter(
        EASYIMAGE_ROOT . '/config/lite-rate',
        (bool) $liteConfig['trusted_proxy'],
        $liteConfig['trusted_proxy_ips'],
        (string) $liteConfig['client_ip_header']
    );
    if (!$rateLimiter->reserveAttempt()) {
        http_response_code(429);
        $error = '初始化尝试过多，请 15 分钟后再试';
    } elseif (!LiteAuth::verifyCsrf($_POST['csrf'] ?? null)) {
        $error = '页面已过期，请刷新后重试';
    } else {
        $setupToken = is_string($_POST['setup_token'] ?? null) ? trim($_POST['setup_token']) : '';
        $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
        if (!LiteSetup::verifyToken($setupState, $setupToken)) {
            $error = '初始化凭证或填写内容无效';
        } elseif ($username === '' || strlen($username) > 64 || preg_match('/[\x00-\x1F\x7F]/', $username) === 1) {
            $error = '初始化凭证或填写内容无效';
        } elseif (mb_strlen($password, 'UTF-8') < 12 || !hash_equals($password, $confirmation)) {
            $error = '密码至少 12 位，并且两次输入必须一致';
        } else {
            try {
                LiteSetup::createLocalConfig(EASYIMAGE_ROOT, $username, $password);
                $rateLimiter->clear();
                session_regenerate_id(true);
                header('Location: ' . LiteUrl::app($liteConfig), true, 303);
                exit;
            } catch (RuntimeException $exception) {
                error_log('PicLite Lite setup write error: ' . $exception->getMessage());
                $error = '初始化失败，请检查服务器日志';
            }
        }
    }
}

function setupEscape(string|int $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$csrf = LiteAuth::csrfToken();
$expiresAt = (new DateTimeImmutable('@' . $setupState['expires_at']))
    ->setTimezone(new DateTimeZone((string) $liteConfig['timezone']));
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>初始化工作台 · PicLite Lite</title>
    <link rel="stylesheet" href="<?= setupEscape(LiteUrl::app($liteConfig, 'assets/app.css')) ?>">
</head>
<body class="setup-page">
    <main class="setup-shell">
        <section class="setup-intro">
            <p class="eyebrow">PicLite Lite / 首次初始化</p>
            <p class="setup-index" aria-hidden="true">00</p>
            <h1>装上<br><em>工作台的锁。</em></h1>
            <p>初始化凭证只会写入服务器日志，不会出现在网页或配置文件中。完成后，此入口将自动关闭。</p>
        </section>
        <section class="setup-panel" aria-labelledby="setup-title">
            <div class="setup-heading">
                <span aria-hidden="true"></span>
                <div><p class="eyebrow">One-time setup</p><h2 id="setup-title">创建管理员</h2></div>
            </div>
            <?php if ($error !== ''): ?><p class="flash error" role="alert"><?= setupEscape($error) ?></p><?php endif; ?>
            <form method="post" class="setup-form">
                <input type="hidden" name="csrf" value="<?= setupEscape($csrf) ?>">
                <label>初始化凭证<input type="password" name="setup_token" autocomplete="off" required autofocus aria-describedby="token-help"></label>
                <small id="token-help">在 Docker 日志中查找 “PicLite Lite setup token”，有效至 <?= setupEscape($expiresAt->format('H:i')) ?>。</small>
                <label>管理员账号<input name="username" autocomplete="username" maxlength="64" required></label>
                <div class="setup-passwords">
                    <label>密码<input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
                    <label>确认密码<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
                </div>
                <button type="submit" class="button primary">完成初始化</button>
            </form>
            <p class="setup-note">配置将以 0600 权限写入 <code>config/lite.local.php</code></p>
        </section>
    </main>
</body>
</html>
