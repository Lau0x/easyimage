<?php

declare(strict_types=1);

final class LiteUploadException extends RuntimeException
{
}

final class LiteUploadService
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private const SOURCE_EXTENSIONS = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    public function __construct(private readonly array $config)
    {
    }

    public function upload(array $file, string|int $uploaderId = 0): array
    {
        $sourceName = is_string($file['name'] ?? null) ? basename($file['name']) : '';
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new LiteUploadException($this->uploadError($error));
        }

        $temporary = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > $this->config['max_size']) {
            throw new LiteUploadException('文件为空或超过 ' . self::formatBytes((int) $this->config['max_size']) . ' 限制');
        }
        if ($temporary === '' || !is_uploaded_file($temporary)) {
            throw new LiteUploadException('上传来源无效');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($temporary);
        $sourceExtension = strtolower((string) pathinfo($sourceName, PATHINFO_EXTENSION));
        if (!isset(self::MIME_EXTENSIONS[$mime])
            || !isset(self::SOURCE_EXTENSIONS[$sourceExtension])
            || self::SOURCE_EXTENSIONS[$sourceExtension] !== $mime) {
            throw new LiteUploadException('仅支持 JPG、PNG、GIF 和 WebP 图片');
        }
        if (@getimagesize($temporary) === false) {
            throw new LiteUploadException('文件不是有效图片');
        }

        $extension = self::MIME_EXTENSIONS[$mime];
        $date = new DateTimeImmutable('now', new DateTimeZone((string) $this->config['timezone']));
        $relativeDirectory = $date->format('Y/m/d');
        $directory = $this->config['upload_root'] . '/' . $relativeDirectory;
        $this->ensureDirectory($directory);

        do {
            $filename = bin2hex(random_bytes(10)) . '.' . $extension;
            $destination = $directory . '/' . $filename;
        } while (file_exists($destination));

        if (!move_uploaded_file($temporary, $destination)) {
            throw new LiteUploadException('无法保存图片，请检查图片目录权限');
        }
        @chmod($destination, 0644);

        $path = LiteUrl::image($this->config, $relativeDirectory . '/' . $filename);
        $url = LiteUrl::absolute($this->config, $path);
        $expires = time() + (int) $this->config['delete_ttl'];
        $signature = LiteDeleteSignature::sign($path, $expires, (string) $this->config['hmac_secret']);
        $deletePath = LiteUrl::app($this->config, 'delete.php') . '?' . http_build_query([
            'path' => $path,
            'expires' => $expires,
            'signature' => $signature,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'result' => 'success',
            'code' => 200,
            'url' => $url,
            'srcName' => $sourceName,
            'thumb' => $url,
            'del' => LiteUrl::absolute($this->config, $deletePath),
            'message' => 'success',
            'id' => $uploaderId,
            'path' => $path,
        ];
    }

    public static function normalizeFiles(array $files): array
    {
        if (!is_array($files['name'] ?? null)) {
            return [$files];
        }

        $normalized = [];
        foreach ($files['name'] as $index => $name) {
            $normalized[] = [
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }
        return $normalized;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1), '0'), '.') . ' MB';
        }
        return (string) ceil($bytes / 1024) . ' KB';
    }

    private function ensureDirectory(string $directory): void
    {
        $root = (string) $this->config['upload_root'];
        if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
            throw new LiteUploadException('图片目录不可用');
        }
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new LiteUploadException('无法创建日期目录');
        }

        $realRoot = realpath($root);
        $realDirectory = realpath($directory);
        if ($realRoot === false || $realDirectory === false || !str_starts_with($realDirectory . '/', $realRoot . '/')) {
            throw new LiteUploadException('图片目录路径无效');
        }
    }

    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件超过服务器上传限制',
            UPLOAD_ERR_PARTIAL => '文件上传不完整',
            UPLOAD_ERR_NO_FILE => '没有选择图片',
            default => '上传失败，错误代码 ' . $error,
        };
    }
}

final class LiteDeleteSignature
{
    public static function sign(string $path, int $expires, string $secret): string
    {
        return hash_hmac('sha256', $path . "\n" . $expires, $secret);
    }

    public static function verify(string $path, int $expires, string $signature, string $secret, string $publicPath): bool
    {
        $prefix = preg_quote('/' . trim($publicPath, '/'), '#');
        return $expires >= time()
            && preg_match('#^' . $prefix . '/\d{4}/\d{2}/\d{2}/[a-f0-9]{20}\.(?:jpg|png|gif|webp)$#D', $path) === 1
            && hash_equals(self::sign($path, $expires, $secret), $signature);
    }
}
