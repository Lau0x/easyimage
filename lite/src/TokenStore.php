<?php

declare(strict_types=1);

final class LiteTokenStore
{
    private const VERSION = 1;
    private const MAX_ACTIVE_TOKENS = 50;
    private const MAX_FILE_SIZE = 1048576;
    private const FILE_PREFIX = "<?php exit; ?>\n";
    private const ALLOWED_DAYS = [30, 90, 365];

    private readonly string $file;
    private readonly string $lockFile;

    public function __construct(string $configDirectory)
    {
        $directory = rtrim($configDirectory, '/');
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Lite token 配置目录不可写');
        }
        $this->file = $directory . '/lite.tokens.php';
        $this->lockFile = $directory . '/lite.tokens.lock';
        $this->assertSafePath($this->file);
        $this->assertSafePath($this->lockFile);
    }

    public function create(string $label, int $days): array
    {
        $label = trim($label);
        if (preg_match('//u', $label) !== 1
            || mb_strlen($label, 'UTF-8') < 1
            || mb_strlen($label, 'UTF-8') > 64
            || preg_match('/[\x00-\x1F\x7F]/u', $label) === 1
        ) {
            throw new InvalidArgumentException('凭证名称需为 1 至 64 个字符');
        }
        if (!in_array($days, self::ALLOWED_DAYS, true)) {
            throw new InvalidArgumentException('凭证有效期无效');
        }

        return $this->withLock(LOCK_EX, function () use ($label, $days): array {
            $data = $this->loadData();
            $now = time();
            $active = array_filter($data['tokens'], static fn (array $token): bool => $token['expires_at'] > $now);
            if (count($active) >= self::MAX_ACTIVE_TOKENS) {
                throw new RuntimeException('最多保留 50 个有效 API 凭证');
            }

            $raw = 'eil_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $entry = [
                'id' => bin2hex(random_bytes(8)),
                'label' => $label,
                'hash' => hash('sha256', $raw),
                'created_at' => $now,
                'expires_at' => $now + ($days * 86400),
            ];
            $data['tokens'][] = $entry;
            $this->writeData($data);

            return [
                'token' => $raw,
                'id' => $entry['id'],
                'label' => $entry['label'],
                'created_at' => $entry['created_at'],
                'expires_at' => $entry['expires_at'],
            ];
        });
    }

    public function listTokens(): array
    {
        return $this->withLock(LOCK_SH, function (): array {
            $tokens = $this->loadData()['tokens'];
            $now = time();
            $visible = array_map(static fn (array $token): array => [
                'id' => $token['id'],
                'label' => $token['label'],
                'created_at' => $token['created_at'],
                'expires_at' => $token['expires_at'],
                'status' => $token['expires_at'] > $now ? 'active' : 'expired',
            ], $tokens);
            usort($visible, static fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
            return $visible;
        });
    }

    public function revoke(string $id): bool
    {
        if (preg_match('/^[a-f0-9]{16}$/D', $id) !== 1) {
            return false;
        }

        return $this->withLock(LOCK_EX, function () use ($id): bool {
            $data = $this->loadData();
            $remaining = array_values(array_filter(
                $data['tokens'],
                static fn (array $token): bool => !hash_equals($token['id'], $id)
            ));
            if (count($remaining) === count($data['tokens'])) {
                return false;
            }
            $data['tokens'] = $remaining;
            $this->writeData($data);
            return true;
        });
    }

    public function validate(string $raw): ?array
    {
        return $this->withLock(LOCK_SH, function () use ($raw): ?array {
            $candidateHash = hash('sha256', $raw);
            $matched = null;
            $now = time();
            foreach ($this->loadData()['tokens'] as $token) {
                if ($token['expires_at'] <= $now) {
                    continue;
                }
                $equal = hash_equals($token['hash'], $candidateHash);
                if ($equal) {
                    $matched = ['id' => $token['id'], 'label' => $token['label']];
                }
            }
            return $matched;
        });
    }

    private function withLock(int $operation, callable $callback): mixed
    {
        $this->assertSafePath($this->lockFile);
        $handle = fopen($this->lockFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException('无法打开 Lite token 锁文件');
        }
        @chmod($this->lockFile, 0600);
        if (!flock($handle, $operation)) {
            fclose($handle);
            throw new RuntimeException('无法锁定 Lite token 文件');
        }

        try {
            $this->assertSafePath($this->file);
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function loadData(): array
    {
        if (!file_exists($this->file)) {
            return ['version' => self::VERSION, 'tokens' => []];
        }
        $this->assertSafePath($this->file);
        $size = filesize($this->file);
        if ($size === false || $size < strlen(self::FILE_PREFIX) || $size > self::MAX_FILE_SIZE) {
            throw new RuntimeException('Lite token 文件大小无效');
        }
        $contents = file_get_contents($this->file);
        if (!is_string($contents) || !str_starts_with($contents, self::FILE_PREFIX)) {
            throw new RuntimeException('Lite token 文件格式无效');
        }
        try {
            $data = json_decode(substr($contents, strlen(self::FILE_PREFIX)), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Lite token 文件 JSON 无效', 0, $exception);
        }
        return $this->validateData($data);
    }

    private function validateData(mixed $data): array
    {
        if (!is_array($data)
            || array_keys($data) !== ['version', 'tokens']
            || $data['version'] !== self::VERSION
            || !is_array($data['tokens'])
            || !array_is_list($data['tokens'])
        ) {
            throw new RuntimeException('Lite token 文件结构无效');
        }

        $ids = [];
        $hashes = [];
        $activeCount = 0;
        $now = time();
        foreach ($data['tokens'] as $token) {
            if (!is_array($token)
                || array_keys($token) !== ['id', 'label', 'hash', 'created_at', 'expires_at']
                || !is_string($token['id'])
                || preg_match('/^[a-f0-9]{16}$/D', $token['id']) !== 1
                || !is_string($token['label'])
                || preg_match('//u', $token['label']) !== 1
                || mb_strlen($token['label'], 'UTF-8') < 1
                || mb_strlen($token['label'], 'UTF-8') > 64
                || preg_match('/[\x00-\x1F\x7F]/u', $token['label']) === 1
                || !is_string($token['hash'])
                || preg_match('/^[a-f0-9]{64}$/D', $token['hash']) !== 1
                || !is_int($token['created_at'])
                || !is_int($token['expires_at'])
                || $token['created_at'] < 1
                || $token['expires_at'] <= $token['created_at']
                || isset($ids[$token['id']])
                || isset($hashes[$token['hash']])
            ) {
                throw new RuntimeException('Lite token 条目无效');
            }
            $ids[$token['id']] = true;
            $hashes[$token['hash']] = true;
            if ($token['expires_at'] > $now) {
                $activeCount++;
            }
        }
        if ($activeCount > self::MAX_ACTIVE_TOKENS) {
            throw new RuntimeException('Lite token 有效条目超过上限');
        }
        return $data;
    }

    private function writeData(array $data): void
    {
        $data = $this->validateData($data);
        $temporary = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('无法编码 Lite token 文件', 0, $exception);
        }
        $payload = self::FILE_PREFIX . $json . "\n";
        $written = file_put_contents($temporary, $payload, LOCK_EX);
        if ($written !== strlen($payload) || !chmod($temporary, 0600)) {
            @unlink($temporary);
            throw new RuntimeException('无法安全写入 Lite token 文件');
        }
        $this->assertSafePath($this->file);
        if (!rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new RuntimeException('无法原子替换 Lite token 文件');
        }
        @chmod($this->file, 0600);
    }

    private function assertSafePath(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Lite token 安全文件路径无效');
        }
    }
}
