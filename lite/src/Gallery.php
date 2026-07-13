<?php

declare(strict_types=1);

final class LiteGallery
{
    public function __construct(private readonly array $config)
    {
    }

    public function date(string $value): DateTimeImmutable
    {
        $timezone = new DateTimeZone((string) $this->config['timezone']);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            return new DateTimeImmutable('today', $timezone);
        }
        return $date;
    }

    public function images(DateTimeImmutable $date): array
    {
        $root = realpath((string) $this->config['upload_root']);
        $relativeDirectory = $date->format('Y/m/d');
        $directoryPath = $this->config['upload_root'] . '/' . $relativeDirectory;
        $segments = explode('/', $relativeDirectory);
        $cursor = (string) $this->config['upload_root'];
        foreach ($segments as $segment) {
            $cursor .= '/' . $segment;
            if (is_link($cursor)) {
                return [];
            }
        }
        $directory = realpath($directoryPath);
        if ($root === false || $directory === false || !is_dir($directory)
            || !str_starts_with($directory . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            return [];
        }

        $images = [];
        try {
            $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $file) {
                if ($file->isLink() || !$file->isFile() || preg_match('/\.(?:jpe?g|png|gif|webp)$/i', $file->getFilename()) !== 1) {
                    continue;
                }
                $realFile = $file->getRealPath();
                if ($realFile === false || !str_starts_with($realFile, $root . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $relative = $date->format('Y/m/d') . '/' . rawurlencode($file->getFilename());
                $path = LiteUrl::image($this->config, $relative);
                $images[] = [
                    'name' => $file->getFilename(),
                    'path' => $path,
                    'delete_path' => LiteUrl::image($this->config, $date->format('Y/m/d') . '/' . $file->getFilename()),
                    'url' => LiteUrl::absolute($this->config, $path),
                    'size' => LiteUploadService::formatBytes($file->getSize()),
                    'mtime' => $file->getMTime(),
                ];
            }
        } catch (RuntimeException) {
            return [];
        }

        usort($images, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        return $images;
    }

    public function delete(string $path): bool
    {
        $file = $this->resolve($path);
        return $file !== null && is_file($file) && unlink($file);
    }

    private function resolve(string $path): ?string
    {
        $prefix = preg_quote('/' . trim((string) $this->config['public_path'], '/'), '#');
        if (preg_match('#^' . $prefix . '/(\d{4}/\d{2}/\d{2}/[A-Za-z0-9._-]+\.(?:jpe?g|png|gif|webp))$#iD', $path, $match) !== 1) {
            return null;
        }

        $root = realpath((string) $this->config['upload_root']);
        $candidate = $this->config['upload_root'] . '/' . $match[1];
        if (is_link($candidate)) {
            return null;
        }
        $file = realpath($candidate);
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $file;
    }
}
