<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
LiteAuth::start($liteConfig);

$error = '';
$notice = '';
$uploads = [];
$today = new DateTimeImmutable('today', new DateTimeZone((string) $liteConfig['timezone']));
$selectedDate = is_string($_GET['date'] ?? null) ? $_GET['date'] : $today->format('Y-m-d');
$requestTooLarge = $_SERVER['REQUEST_METHOD'] === 'POST'
    && $_POST === []
    && $_FILES === []
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;

if ($requestTooLarge) {
    http_response_code(413);
    $error = '请求超过服务器 post_max_size 限制，请减少图片数量或文件大小';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'login') {
        if (!LiteAuth::verifyCsrf($_POST['csrf'] ?? null)) {
            $error = '页面已过期，请刷新后重试';
        } else {
            $rateLimiter = new LiteLoginRateLimiter(
                EASYIMAGE_ROOT . '/config/lite-rate',
                (bool) $liteConfig['trusted_proxy'],
                $liteConfig['trusted_proxy_ips'],
                (string) $liteConfig['client_ip_header']
            );
            if (!$rateLimiter->reserveAttempt()) {
                http_response_code(429);
                $error = '登录尝试过多，请 15 分钟后再试';
            } elseif (LiteAuth::login(
                $liteConfig,
                is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '',
                is_string($_POST['password'] ?? null) ? $_POST['password'] : ''
            )) {
                $rateLimiter->clear();
                liteRedirect(liteAppUrl($liteConfig));
            } else {
                $error = '账号或密码不正确';
            }
        }
    } elseif (!LiteAuth::check() || !LiteAuth::verifyCsrf($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $error = '登录状态或安全令牌无效';
    } elseif ($action === 'logout') {
        LiteAuth::logout();
        liteRedirect(liteAppUrl($liteConfig));
    } elseif ($action === 'delete') {
        $gallery = new LiteGallery($liteConfig);
        $deletePath = is_string($_POST['path'] ?? null) ? $_POST['path'] : '';
        $notice = $gallery->delete($deletePath) ? '图片已删除' : '图片不存在或路径无效';
        $selectedDate = is_string($_POST['date'] ?? null) ? $_POST['date'] : $selectedDate;
    } elseif ($action === 'upload') {
        $service = new LiteUploadService($liteConfig);
        $files = LiteUploadService::normalizeFiles($_FILES['images'] ?? []);
        if (count($files) > $liteConfig['max_files']) {
            $error = '单次最多上传 ' . $liteConfig['max_files'] . ' 张图片';
            $files = [];
        }
        foreach ($files as $file) {
            try {
                $uploads[] = $service->upload($file, 'admin');
            } catch (LiteUploadException $exception) {
                $uploads[] = ['result' => 'failed', 'srcName' => basename((string) ($file['name'] ?? '')), 'message' => $exception->getMessage()];
            }
        }
    }
}

$authenticated = LiteAuth::check();
$csrf = LiteAuth::csrfToken();
$gallery = new LiteGallery($liteConfig);
$date = $gallery->date((string) $selectedDate);
$images = $authenticated ? $gallery->images($date) : [];
$previousDate = $date->modify('-1 day')->format('Y-m-d');
$nextDate = $date->modify('+1 day')->format('Y-m-d');
$appUrl = liteAppUrl($liteConfig);
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>PicLite Lite</title>
    <link rel="stylesheet" href="<?= liteEscape(liteAppUrl($liteConfig, 'assets/app.css')) ?>">
</head>
<body class="<?= $authenticated ? 'workspace' : 'login-page' ?>">
<?php if (!$authenticated): ?>
    <main class="login-shell">
        <section class="login-panel">
            <p class="eyebrow">PicLite Lite / 私有工作台</p>
            <h1>进入工作台</h1>
            <p class="intro">图片留在自己的文件夹里。没有数据库，也没有多余的系统。</p>
            <?php if ($error !== ''): ?><p class="flash error" role="alert"><?= liteEscape($error) ?></p><?php endif; ?>
            <form method="post" class="login-form">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf" value="<?= liteEscape($csrf) ?>">
                <label>账号<input name="username" autocomplete="username" required autofocus></label>
                <label>密码<input type="password" name="password" autocomplete="current-password" required></label>
                <button type="submit" class="button primary">开启工作台</button>
            </form>
            <p class="timezone-note">日期按 <?= liteEscape($liteConfig['timezone']) ?> 自然日归档</p>
        </section>
        <aside class="login-art" aria-hidden="true"><span>01</span><span>显影</span><i></i></aside>
    </main>
<?php else: ?>
    <header class="topbar">
        <a class="brand" href="<?= liteEscape($appUrl) ?>"><b>PL</b><span>PicLite<br>Lite</span></a>
        <nav aria-label="主导航"><a class="mobile-nav-link" href="<?= liteEscape($appUrl) ?>" aria-current="page">工作台</a><a class="desktop-nav-link" href="#upload">上传</a><a class="desktop-nav-link" href="#gallery">图库</a><a href="<?= liteEscape(liteAppUrl($liteConfig, 'tokens.php')) ?>">API 凭证</a></nav>
        <form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= liteEscape($csrf) ?>"><button class="text-button">退出</button></form>
    </header>
    <main class="main-grid">
        <section class="hero">
            <div><p class="eyebrow">无数据库图床 / <?= liteEscape($date->format('Y.m.d')) ?></p><h1>把图片放进<br><em>自己的图床。</em></h1></div>
            <p class="hero-note">上传后直接得到永久图片地址。原图仍写入 <code>i/Y/m/d/</code>，旧链接保持不变。</p>
        </section>

        <?php if ($error !== ''): ?><p class="flash error wide" role="alert"><?= liteEscape($error) ?></p><?php endif; ?>
        <?php if ($notice !== ''): ?><p class="flash wide" role="status"><?= liteEscape($notice) ?></p><?php endif; ?>

        <section class="upload-card" id="upload">
            <div class="section-number">01</div>
            <div class="section-heading"><p class="eyebrow">Drop zone</p><h2>冲洗新图片</h2></div>
            <form method="post" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="csrf" value="<?= liteEscape($csrf) ?>">
                <label class="drop-zone" id="dropZone">
                    <input type="file" name="images[]" id="fileInput" accept="image/jpeg,image/png,image/gif,image/webp" data-max-files="<?= liteEscape($liteConfig['max_files']) ?>" multiple required>
                    <span class="aperture" aria-hidden="true"></span>
                    <strong>拖入图片，或点此选择</strong>
                    <small>JPG / PNG / GIF / WebP · 单张最大 <?= liteEscape(LiteUploadService::formatBytes((int) $liteConfig['max_size'])) ?> · 单次最多 <?= liteEscape($liteConfig['max_files']) ?> 张</small>
                </label>
                <div class="file-strip" id="fileStrip" role="status" hidden></div>
                <button class="button primary upload-button" type="submit">开始显影 <span>↗</span></button>
            </form>
        </section>

        <?php if ($uploads !== []): ?>
            <section class="results-panel" aria-live="polite">
                <div class="section-number">02</div>
                <div class="section-heading"><p class="eyebrow">Developed</p><h2>冲洗结果</h2></div>
                <div class="result-list">
                <?php foreach ($uploads as $upload): ?>
                    <article class="result-row <?= $upload['result'] === 'success' ? '' : 'failed' ?>">
                        <div><strong><?= liteEscape($upload['srcName']) ?></strong><small><?= liteEscape($upload['message']) ?></small></div>
                        <?php if ($upload['result'] === 'success'): ?><button class="copy-button" type="button" data-copy="<?= liteEscape($upload['url']) ?>">复制直链</button><?php endif; ?>
                    </article>
                <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="gallery-panel" id="gallery">
            <div class="gallery-head">
                <div><p class="eyebrow">Contact sheet</p><h2><?= liteEscape($date->format('Y年m月d日')) ?></h2><span><?= count($images) ?> 张图片</span></div>
                <div class="date-nav"><a href="?date=<?= $previousDate ?>" aria-label="前一天">←</a><form method="get"><label class="visually-hidden" for="galleryDate">选择图库日期</label><input id="galleryDate" type="date" name="date" value="<?= liteEscape($date->format('Y-m-d')) ?>" onchange="this.form.submit()"></form><a href="?date=<?= $nextDate ?>" aria-label="后一天">→</a></div>
            </div>
            <?php if ($images === []): ?>
                <div class="empty-state"><span>空白底片</span><p>这一天还没有图片。</p></div>
            <?php else: ?>
                <div class="contact-sheet">
                <?php foreach ($images as $image): ?>
                    <article class="frame">
                        <a href="<?= liteEscape($image['url']) ?>" target="_blank" rel="noopener" aria-label="打开原图 <?= liteEscape($image['name']) ?>"><img src="<?= liteEscape($image['path']) ?>" alt="<?= liteEscape($image['name']) ?>" loading="lazy"></a>
                        <div class="frame-meta"><div><strong><?= liteEscape($image['name']) ?></strong><small><?= liteEscape($image['size']) ?></small></div><div class="frame-actions"><button type="button" data-copy="<?= liteEscape($image['url']) ?>">复制</button><form method="post" onsubmit="return confirm('确认删除这张图片？')"><input type="hidden" name="action" value="delete"><input type="hidden" name="csrf" value="<?= liteEscape($csrf) ?>"><input type="hidden" name="path" value="<?= liteEscape($image['delete_path']) ?>"><input type="hidden" name="date" value="<?= liteEscape($date->format('Y-m-d')) ?>"><button class="danger">删除</button></form></div></div>
                    </article>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <footer><span>PicLite Lite</span><span>Filesystem first · <?= liteEscape($liteConfig['timezone']) ?></span></footer>
<?php endif; ?>
<script src="<?= liteEscape(liteAppUrl($liteConfig, 'assets/app.js')) ?>" defer></script>
</body>
</html>
