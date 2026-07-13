<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
LiteAuth::start($liteConfig);

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

if (!LiteAuth::check()) {
    header('Location: ' . liteAppUrl($liteConfig), true, 302);
    exit;
}

$error = '';
$storeAvailable = true;
$store = null;
$createdToken = null;
if (!is_string($_SESSION['lite_token_create_nonce'] ?? null)
    || preg_match('/^[a-f0-9]{64}$/D', $_SESSION['lite_token_create_nonce']) !== 1
) {
    $_SESSION['lite_token_create_nonce'] = bin2hex(random_bytes(32));
}
$createNonce = $_SESSION['lite_token_create_nonce'];
try {
    $store = new LiteTokenStore(EASYIMAGE_ROOT . '/config');
} catch (RuntimeException $exception) {
    error_log('PicLite Lite token store error: ' . $exception->getMessage());
    http_response_code(500);
    $error = 'API 凭证存储不可用，请检查服务器日志';
    $storeAvailable = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $storeAvailable && $store instanceof LiteTokenStore) {
    if (!LiteAuth::verifyCsrf($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $error = '页面已过期，请刷新后重试';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        try {
            if ($action === 'create') {
                $submittedNonce = is_string($_POST['create_nonce'] ?? null) ? $_POST['create_nonce'] : '';
                if (!hash_equals($createNonce, $submittedNonce)) {
                    throw new InvalidArgumentException('创建请求已使用或失效，请重新提交');
                }
                unset($_SESSION['lite_token_create_nonce']);
                $days = is_string($_POST['days'] ?? null) && preg_match('/^(?:30|90|365)$/D', $_POST['days']) === 1
                    ? (int) $_POST['days']
                    : 0;
                $createdToken = $store->create(
                    is_string($_POST['label'] ?? null) ? $_POST['label'] : '',
                    $days
                );
            } elseif ($action === 'revoke') {
                $revoked = $store->revoke(is_string($_POST['id'] ?? null) ? $_POST['id'] : '');
                $_SESSION['lite_token_notice'] = $revoked ? 'API 凭证已吊销' : 'API 凭证不存在或已经吊销';
                header('Location: ' . liteAppUrl($liteConfig, 'tokens.php'), true, 303);
                exit;
            } else {
                $error = '操作无效';
            }
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (RuntimeException $exception) {
            error_log('PicLite Lite token store error: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'API 凭证存储不可用，请检查服务器日志';
            $storeAvailable = false;
        }
    }
}

if (!is_string($_SESSION['lite_token_create_nonce'] ?? null)) {
    $_SESSION['lite_token_create_nonce'] = bin2hex(random_bytes(32));
}
$createNonce = $_SESSION['lite_token_create_nonce'];
$notice = is_string($_SESSION['lite_token_notice'] ?? null) ? $_SESSION['lite_token_notice'] : '';
unset($_SESSION['lite_token_notice']);
$tokens = [];
if ($storeAvailable && $store instanceof LiteTokenStore) {
    try {
        $tokens = $store->listTokens();
    } catch (RuntimeException $exception) {
        error_log('PicLite Lite token store error: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'API 凭证存储不可用，请检查服务器日志';
        $storeAvailable = false;
    }
}

$csrf = LiteAuth::csrfToken();
$timezone = new DateTimeZone((string) $liteConfig['timezone']);
$formatTime = static function (int $timestamp) use ($timezone): string {
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('Y-m-d H:i');
};
$activeCount = count(array_filter($tokens, static fn (array $token): bool => $token['status'] === 'active'));
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>API 凭证 · PicLite Lite</title>
    <link rel="stylesheet" href="<?= liteEscape(liteAppUrl($liteConfig, 'assets/app.css')) ?>">
</head>
<body class="token-page">
    <header class="topbar token-topbar">
        <a class="brand" href="<?= liteEscape(liteAppUrl($liteConfig)) ?>"><b>PL</b><span>PicLite<br>Lite</span></a>
        <nav aria-label="主导航"><a href="<?= liteEscape(liteAppUrl($liteConfig)) ?>">工作台</a><a href="<?= liteEscape(liteAppUrl($liteConfig, 'tokens.php')) ?>" aria-current="page">API 凭证</a></nav>
        <span class="cabinet-mark">Cabinet 02</span>
    </header>

    <main class="token-shell">
        <section class="token-hero">
            <div>
                <p class="eyebrow">API access / 私有药剂柜</p>
                <h1>给自动化流程<br><em>一把限时钥匙。</em></h1>
            </div>
            <div class="token-gauge" aria-label="<?= liteEscape($activeCount) ?> 个有效凭证">
                <span><?= liteEscape(str_pad((string) $activeCount, 2, '0', STR_PAD_LEFT)) ?></span>
                <small>Active<br>reagents</small>
            </div>
        </section>

        <?php if (!$liteConfig['api_enabled']): ?>
            <p class="flash error token-flash" role="alert">API 当前关闭。凭证可以预先创建，但只有启用 <code>api_enabled</code> 后才能上传。</p>
        <?php endif; ?>
        <?php if ($error !== ''): ?><p class="flash error token-flash" role="alert"><?= liteEscape($error) ?></p><?php endif; ?>
        <?php if ($notice !== ''): ?><p class="flash token-flash" role="status"><?= liteEscape($notice) ?></p><?php endif; ?>

        <?php if (is_array($createdToken) && is_string($createdToken['token'] ?? null)): ?>
            <section class="token-reveal" role="status" aria-labelledby="new-token-title">
                <div><p class="eyebrow">One-time reveal</p><h2 id="new-token-title"><?= liteEscape((string) ($createdToken['label'] ?? '新凭证')) ?></h2><p>原始凭证只显示这一次。离开或刷新页面后无法再次查看。</p></div>
                <div class="token-secret"><code><?= liteEscape($createdToken['token']) ?></code><button type="button" class="button primary" data-copy="<?= liteEscape($createdToken['token']) ?>" aria-describedby="token-copy-status">复制凭证</button><span id="token-copy-status" class="visually-hidden" data-copy-status aria-live="polite"></span></div>
            </section>
        <?php endif; ?>

        <section class="cabinet-grid">
            <div class="cabinet-label"><span>01</span><p class="eyebrow">Mix a key</p><h2>配制新凭证</h2><p>凭证过期后立即失效。原始值不会写入凭证文件或服务器日志。</p></div>
            <form method="post" class="token-create-form">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="csrf" value="<?= liteEscape($csrf) ?>">
                <input type="hidden" name="create_nonce" value="<?= liteEscape($createNonce) ?>">
                <label>凭证名称<input name="label" maxlength="64" required placeholder="例如：PicGo on Mac" <?= $storeAvailable ? '' : 'disabled' ?>></label>
                <label>有效期<select name="days" <?= $storeAvailable ? '' : 'disabled' ?>><option value="30">30 天</option><option value="90" selected>90 天</option><option value="365">365 天</option></select></label>
                <button type="submit" class="button primary" <?= $storeAvailable ? '' : 'disabled' ?>>生成一次性凭证</button>
            </form>
        </section>

        <section class="token-inventory" aria-labelledby="inventory-title">
            <div class="inventory-heading"><div><p class="eyebrow">Inventory / <?= liteEscape(count($tokens)) ?></p><h2 id="inventory-title">柜内凭证</h2></div><code><?= liteEscape(liteAppUrl($liteConfig, 'api.php')) ?></code></div>
            <?php if ($tokens === []): ?>
                <div class="token-empty"><span aria-hidden="true">02</span><p>药剂柜还是空的。创建凭证后，自动化工具才能调用上传 API。</p></div>
            <?php else: ?>
                <div class="token-table-wrap" tabindex="0" aria-label="API 凭证列表，可横向滚动">
                    <table class="token-table">
                        <thead><tr><th scope="col">名称</th><th scope="col">创建时间</th><th scope="col">过期时间</th><th scope="col">状态</th><th scope="col"><span class="visually-hidden">操作</span></th></tr></thead>
                        <tbody>
                        <?php foreach ($tokens as $token): ?>
                            <tr>
                                <th scope="row"><strong><?= liteEscape($token['label']) ?></strong><small><?= liteEscape($token['id']) ?></small></th>
                                <td><time datetime="<?= liteEscape(date(DATE_ATOM, $token['created_at'])) ?>"><?= liteEscape($formatTime($token['created_at'])) ?></time></td>
                                <td><time datetime="<?= liteEscape(date(DATE_ATOM, $token['expires_at'])) ?>"><?= liteEscape($formatTime($token['expires_at'])) ?></time></td>
                                <td><span class="token-status <?= $token['status'] === 'active' ? 'active' : 'expired' ?>"><?= $token['status'] === 'active' ? '有效' : '已过期' ?></span></td>
                                <td><form method="post" onsubmit="return confirm('确认吊销这个 API 凭证？')"><input type="hidden" name="action" value="revoke"><input type="hidden" name="csrf" value="<?= liteEscape($csrf) ?>"><input type="hidden" name="id" value="<?= liteEscape($token['id']) ?>"><button type="submit" class="token-revoke">吊销</button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <footer><span>PicLite Lite / API Cabinet</span><span>Token hashes only · <?= liteEscape($liteConfig['timezone']) ?></span></footer>
    <script src="<?= liteEscape(liteAppUrl($liteConfig, 'assets/app.js')) ?>" defer></script>
</body>
</html>
