<?php

declare(strict_types=1);

final class LiteConfig
{
    public static function load(string $root, bool $loadSecret = true): array
    {
        $defaults = [
            'base_url' => '',
            'username' => 'admin',
            'password' => '',
            'max_size' => 10 * 1024 * 1024,
            'max_files' => 10,
            'timezone' => 'Asia/Shanghai',
            'public_path' => '/i',
            'app_path' => '/lite',
            'upload_root' => $root . '/i',
            'api_enabled' => false,
            'api_tokens' => [],
            'allow_legacy_api_tokens' => false,
            'delete_ttl' => 86400,
            'session_name' => 'easyimage_lite',
            'trusted_proxy' => false,
            'trusted_proxy_ips' => [],
            'client_ip_header' => 'HTTP_X_REAL_IP',
        ];

        $disableLegacy = self::environmentBoolean('LITE_DISABLE_LEGACY_CONFIG', false);
        $legacySource = $disableLegacy ? [] : self::loadLegacy($root . '/config/config.php', 'config');
        $legacyTokens = $disableLegacy ? [] : self::loadLegacy($root . '/config/api_key.php', 'tokenList');
        $legacy = [
            'base_url' => (string) ($legacySource['domain'] ?? ''),
            'username' => (string) ($legacySource['user'] ?? 'admin'),
            'password' => self::isPasswordHash((string) ($legacySource['password'] ?? ''))
                ? (string) $legacySource['password']
                : '',
            'max_size' => max(1, (int) ($legacySource['maxSize'] ?? $defaults['max_size'])),
            'timezone' => (string) ($legacySource['timezone'] ?? $defaults['timezone']),
            'api_enabled' => (bool) ($legacySource['apiStatus'] ?? false),
        ];

        [$localExists, $local] = self::loadLocal($root . '/config/lite.local.php');
        if (array_key_exists('password', $local) && !self::isPasswordHash((string) $local['password'])) {
            throw new RuntimeException('Lite 本地配置中的管理员密码哈希无效');
        }

        $environment = self::loadEnvironment();
        $config = array_replace($defaults, $legacy, $local, $environment);
        $config['timezone'] = self::timezone((string) $config['timezone']);
        if (!self::validBaseUrl((string) $config['base_url'])) {
            throw new RuntimeException('Lite base_url 只能是 http/https origin');
        }
        $config['base_url'] = rtrim((string) $config['base_url'], '/');
        $config['public_path'] = '/' . trim((string) $config['public_path'], '/');
        $config['app_path'] = self::appPath((string) $config['app_path']);
        $config['upload_root'] = rtrim((string) $config['upload_root'], '/');
        $config['max_size'] = max(1, (int) $config['max_size']);
        $config['max_files'] = max(1, min(50, (int) $config['max_files']));
        $config['delete_ttl'] = max(60, (int) $config['delete_ttl']);
        $config['trusted_proxy'] = self::booleanValue($config['trusted_proxy'], 'trusted_proxy');
        $config['trusted_proxy_ips'] = self::ipList($config['trusted_proxy_ips']);
        $config['client_ip_header'] = strtoupper((string) $config['client_ip_header']);
        $config['api_enabled'] = self::booleanValue($config['api_enabled'], 'api_enabled');
        $config['allow_legacy_api_tokens'] = self::booleanValue($config['allow_legacy_api_tokens'], 'allow_legacy_api_tokens');
        $localTokens = is_array($config['api_tokens']) ? $config['api_tokens'] : [];
        $config['api_tokens'] = $config['allow_legacy_api_tokens']
            ? array_replace(is_array($legacyTokens) ? $legacyTokens : [], $localTokens)
            : $localTokens;

        if (preg_match('/^HTTP_[A-Z0-9_]+$/D', $config['client_ip_header']) !== 1) {
            throw new RuntimeException('Lite client_ip_header 格式无效');
        }

        $config['needs_setup'] = !self::isPasswordHash((string) $config['password']);
        if ($config['needs_setup'] && $localExists) {
            throw new RuntimeException('Lite 本地配置缺少有效的管理员密码哈希');
        }
        if ($loadSecret && !$config['needs_setup']) {
            $config['hmac_secret'] = self::loadSecret($root . '/config/lite.secret.php');
        }

        return $config;
    }

    public static function isPasswordHash(string $password): bool
    {
        if ($password === '') {
            return false;
        }
        $info = password_get_info($password);
        return isset($info['algoName']) && $info['algoName'] !== 'unknown';
    }

    private static function loadEnvironment(): array
    {
        $map = [
            'LITE_BASE_URL' => 'base_url',
            'LITE_APP_PATH' => 'app_path',
            'LITE_TIMEZONE' => 'timezone',
            'LITE_ADMIN_USER' => 'username',
            'LITE_MAX_FILE_SIZE' => 'max_size',
            'LITE_MAX_FILES' => 'max_files',
            'LITE_CLIENT_IP_HEADER' => 'client_ip_header',
        ];
        $environment = [];
        foreach ($map as $name => $key) {
            $value = self::environmentValue($name);
            if ($value !== null) {
                $environment[$key] = $value;
            }
        }

        foreach (['LITE_MAX_FILE_SIZE' => 'max_size', 'LITE_MAX_FILES' => 'max_files'] as $name => $key) {
            if (array_key_exists($key, $environment)
                && (preg_match('/^[0-9]+$/D', (string) $environment[$key]) !== 1 || (int) $environment[$key] < 1)
            ) {
                throw new RuntimeException($name . ' 必须是正整数');
            }
        }

        $trustedProxy = self::environmentValue('LITE_TRUSTED_PROXY');
        if ($trustedProxy !== null) {
            $environment['trusted_proxy'] = self::parseBoolean($trustedProxy, 'LITE_TRUSTED_PROXY');
        }
        $apiEnabled = self::environmentValue('LITE_API_ENABLED');
        if ($apiEnabled !== null) {
            $environment['api_enabled'] = self::parseBoolean($apiEnabled, 'LITE_API_ENABLED');
        }
        $trustedIps = self::environmentValue('LITE_TRUSTED_PROXY_IPS');
        if ($trustedIps !== null) {
            $environment['trusted_proxy_ips'] = preg_split('/\s*,\s*/', $trustedIps);
        }

        $hashFile = self::environmentValue('LITE_ADMIN_PASSWORD_HASH_FILE');
        $directHash = self::environmentValue('LITE_ADMIN_PASSWORD_HASH');
        if ($hashFile !== null) {
            if ($hashFile === '' || !is_file($hashFile) || !is_readable($hashFile)) {
                throw new RuntimeException('LITE_ADMIN_PASSWORD_HASH_FILE 无法读取');
            }
            $contents = file_get_contents($hashFile);
            $hash = is_string($contents) ? trim($contents) : '';
            if (!self::isPasswordHash($hash)) {
                throw new RuntimeException('LITE_ADMIN_PASSWORD_HASH_FILE 内容不是有效 password_hash');
            }
            $environment['password'] = $hash;
        } elseif ($directHash !== null) {
            if (!self::isPasswordHash($directHash)) {
                throw new RuntimeException('LITE_ADMIN_PASSWORD_HASH 不是有效 password_hash');
            }
            $environment['password'] = $directHash;
        }

        return $environment;
    }

    private static function validBaseUrl(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host'])
            && (string) $parts['host'] !== ''
            && !array_key_exists('user', $parts)
            && !array_key_exists('pass', $parts)
            && !array_key_exists('query', $parts)
            && !array_key_exists('fragment', $parts)
            && (!array_key_exists('path', $parts) || $parts['path'] === '' || $parts['path'] === '/');
    }

    private static function appPath(string $path): string
    {
        if (preg_match('#^/(?:[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*)?$#D', $path) !== 1) {
            throw new RuntimeException('Lite app_path 格式无效');
        }
        return $path;
    }

    private static function loadSecret(string $file): string
    {
        if (is_link($file)) {
            throw new RuntimeException('Lite 独立密钥路径不能是符号链接');
        }
        if (!is_file($file)) {
            self::createSecret($file);
        }

        $secret = include $file;
        if (!is_string($secret) || preg_match('/^[a-f0-9]{64}$/D', $secret) !== 1) {
            throw new RuntimeException('Lite 独立密钥文件无效：' . $file);
        }
        return $secret;
    }

    private static function createSecret(string $file): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('无法创建 Lite 独立密钥，请检查 config 目录写权限');
        }

        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $payload = "<?php\n\nreturn '" . bin2hex(random_bytes(32)) . "';\n";
        if (file_put_contents($temporary, $payload, LOCK_EX) === false || !chmod($temporary, 0600)) {
            @unlink($temporary);
            throw new RuntimeException('无法安全写入 Lite 独立密钥');
        }

        if (!@link($temporary, $file) && !is_file($file)) {
            @unlink($temporary);
            throw new RuntimeException('无法原子创建 Lite 独立密钥');
        }
        @unlink($temporary);
    }

    private static function loadLegacy(string $file, string $variable): array
    {
        if (!is_file($file)) {
            return [];
        }

        return (static function (string $file, string $variable): array {
            include $file;
            $value = ${$variable} ?? [];
            return is_array($value) ? $value : [];
        })($file, $variable);
    }

    private static function loadLocal(string $file): array
    {
        if (is_link($file)) {
            throw new RuntimeException('Lite 本地配置路径不能是符号链接');
        }
        if (!is_file($file)) {
            return [false, []];
        }

        $value = (static function (string $file): mixed {
            $liteConfig = null;
            $returned = include $file;
            return is_array($returned) ? $returned : $liteConfig;
        })($file);
        if (!is_array($value)) {
            throw new RuntimeException('Lite 本地配置必须返回数组');
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new RuntimeException('Lite 本地配置键名必须是字符串');
            }
        }
        return [true, $value];
    }

    private static function timezone(string $timezone): string
    {
        try {
            new DateTimeZone($timezone);
            return $timezone;
        } catch (Throwable) {
            throw new RuntimeException('Lite timezone 格式无效');
        }
    }

    private static function ipList(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Lite trusted_proxy_ips 必须是数组或逗号分隔的环境变量');
        }
        $addresses = [];
        foreach ($value as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new RuntimeException('Lite trusted_proxy_ips 包含无效 IP');
            }
            $addresses[] = $ip;
        }
        return array_values(array_unique($addresses));
    }

    private static function booleanValue(mixed $value, string $name): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return (bool) $value;
        }
        if (is_string($value)) {
            return self::parseBoolean($value, $name);
        }
        throw new RuntimeException('Lite ' . $name . ' 必须是布尔值');
    }

    private static function environmentBoolean(string $name, bool $default): bool
    {
        $value = self::environmentValue($name);
        return $value === null ? $default : self::parseBoolean($value, $name);
    }

    private static function environmentValue(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private static function parseBoolean(string $value, string $name): bool
    {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        throw new RuntimeException($name . ' 必须使用 1/0、true/false、yes/no 或 on/off');
    }
}

final class LiteUrl
{
    public static function app(array $config, string $path = ''): string
    {
        return rtrim((string) $config['app_path'], '/') . ($path === '' ? '/' : '/' . ltrim($path, '/'));
    }

    public static function image(array $config, string $relative): string
    {
        return rtrim((string) $config['public_path'], '/') . '/' . ltrim($relative, '/');
    }

    public static function absolute(array $config, string $path): string
    {
        return (string) $config['base_url'] !== '' ? $config['base_url'] . $path : $path;
    }
}
