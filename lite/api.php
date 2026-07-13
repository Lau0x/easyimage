<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function apiResponse(string $result, int $code, string $message, array $extra = [], int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode(array_replace([
        'result' => $result,
        'code' => $code,
        'url' => '',
        'srcName' => '',
        'thumb' => '',
        'del' => '',
        'message' => $message,
        'id' => 0,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    apiResponse('failed', 405, 'Method Not Allowed', [], 405);
}
$requestTooLarge = $_POST === []
    && $_FILES === []
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
if ($requestTooLarge) {
    apiResponse('failed', 413, '请求超过服务器 post_max_size 限制', [], 413);
}
if (!$liteConfig['api_enabled']) {
    apiResponse('failed', 201, 'API Closed', [], 403);
}

$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$bearer = preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1 ? trim($match[1]) : '';
$headerToken = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
$postToken = is_string($_POST['token'] ?? null) ? trim($_POST['token']) : '';
$token = $headerToken !== '' ? $headerToken : ($bearer !== '' ? $bearer : $postToken);
$identity = null;
try {
    $identity = (new LiteTokenStore(EASYIMAGE_ROOT . '/config'))->validate($token);
} catch (RuntimeException $exception) {
    error_log('PicLite Lite token store error: ' . $exception->getMessage());
    apiResponse('failed', 500, 'Token Store Error', [], 500);
}

$legacyExpiredId = null;
if ($identity === null && $liteConfig['allow_legacy_api_tokens']) {
    foreach ($liteConfig['api_tokens'] as $knownToken => $entry) {
        if (!is_string($knownToken)) {
            continue;
        }
        $equal = hash_equals($knownToken, $token);
        if (!$equal) {
            continue;
        }
        $id = is_array($entry) ? ($entry['id'] ?? 0) : $entry;
        if (is_array($entry) && isset($entry['expired']) && (int) $entry['expired'] <= time()) {
            $legacyExpiredId = $id;
        } else {
            $identity = ['id' => $id, 'label' => 'Legacy token'];
        }
    }
}
if ($identity === null && $legacyExpiredId !== null) {
    apiResponse('failed', 203, 'Token Expired', ['id' => $legacyExpiredId], 401);
}
if ($token === '' || $identity === null) {
    apiResponse('failed', 202, 'Token Error', [], 401);
}

$tokenId = $identity['id'];
if (!isset($_FILES['image']) || is_array($_FILES['image']['name'] ?? null)) {
    apiResponse('failed', 204, '没有选择上传的文件', ['id' => $tokenId], 400);
}

try {
    $upload = (new LiteUploadService($liteConfig))->upload($_FILES['image'], $tokenId);
    apiResponse('success', 200, 'success', $upload);
} catch (LiteUploadException $exception) {
    apiResponse('failed', 400, $exception->getMessage(), [
        'srcName' => basename((string) ($_FILES['image']['name'] ?? '')),
        'id' => $tokenId,
    ], 400);
}
