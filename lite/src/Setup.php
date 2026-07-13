<?php

declare(strict_types=1);

final class LiteSetup
{
    private const TOKEN_TTL = 1800;

    public static function ensureToken(string $root): array
    {
        $file = $root . '/config/lite.setup.php';
        if (is_link($file)) {
            throw new RuntimeException('Lite 初始化令牌路径不能是符号链接');
        }

        if (is_file($file)) {
            $state = self::readToken($file);
            if ($state['expires_at'] > time()) {
                return $state;
            }
            if (!@unlink($file) && is_file($file)) {
                throw new RuntimeException('无法轮换过期的 Lite 初始化令牌');
            }
        }

        $token = bin2hex(random_bytes(32));
        $state = [
            'token_hash' => hash('sha256', $token),
            'expires_at' => time() + self::TOKEN_TTL,
        ];
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $payload = "<?php\n\nreturn " . var_export($state, true) . ";\n";
        if (file_put_contents($temporary, $payload, LOCK_EX) === false || !chmod($temporary, 0600)) {
            @unlink($temporary);
            throw new RuntimeException('无法安全写入 Lite 初始化令牌');
        }

        if (@link($temporary, $file)) {
            @unlink($temporary);
            error_log('PicLite Lite setup token (expires in 30 minutes): ' . $token);
            return $state;
        }
        @unlink($temporary);
        if (is_file($file)) {
            return self::readToken($file);
        }
        throw new RuntimeException('无法原子创建 Lite 初始化令牌');
    }

    public static function verifyToken(array $state, string $token): bool
    {
        return $token !== ''
            && (int) ($state['expires_at'] ?? 0) > time()
            && isset($state['token_hash'])
            && is_string($state['token_hash'])
            && hash_equals($state['token_hash'], hash('sha256', $token));
    }

    public static function createLocalConfig(string $root, string $username, string $password): void
    {
        $file = $root . '/config/lite.local.php';
        if (is_link($file)) {
            throw new RuntimeException('Lite 本地配置路径不能是符号链接');
        }
        if (file_exists($file)) {
            throw new RuntimeException('Lite 本地配置已存在');
        }

        $config = [
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ];
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($temporary, $payload, LOCK_EX) === false || !chmod($temporary, 0600)) {
            @unlink($temporary);
            throw new RuntimeException('无法安全写入 Lite 本地配置');
        }
        if (!@link($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('无法原子创建 Lite 本地配置');
        }
        @unlink($temporary);

        $setupFile = $root . '/config/lite.setup.php';
        if (is_link($setupFile) || (is_file($setupFile) && !@unlink($setupFile))) {
            error_log('PicLite Lite setup token cleanup failed after configuration was created');
        }
    }

    private static function readToken(string $file): array
    {
        $state = include $file;
        if (!is_array($state)
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($state['token_hash'] ?? '')) !== 1
            || !is_int($state['expires_at'] ?? null)
        ) {
            throw new RuntimeException('Lite 初始化令牌文件无效');
        }
        return $state;
    }
}
