<?php

declare(strict_types=1);

final class LiteAuth
{
    public static function start(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        session_name((string) $config['session_name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (string) $config['app_path'],
            'secure' => self::isHttps($config),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function login(array $config, string $username, string $password): bool
    {
        if (!hash_equals((string) $config['username'], $username)) {
            return false;
        }

        if (!password_verify($password, (string) $config['password'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['lite_authenticated'] = true;
        $_SESSION['lite_username'] = $username;
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return isset($_SESSION['lite_authenticated']) && $_SESSION['lite_authenticated'] === true;
    }

    public static function csrfToken(): string
    {
        if (!isset($_SESSION['lite_csrf']) || !is_string($_SESSION['lite_csrf'])) {
            $_SESSION['lite_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['lite_csrf'];
    }

    public static function verifyCsrf(mixed $token): bool
    {
        return is_string($token)
            && isset($_SESSION['lite_csrf'])
            && is_string($_SESSION['lite_csrf'])
            && hash_equals($_SESSION['lite_csrf'], $token);
    }

    private static function isHttps(array $config): bool
    {
        $direct = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $baseScheme = parse_url((string) ($config['base_url'] ?? ''), PHP_URL_SCHEME);
        $configured = is_string($baseScheme) && strtolower($baseScheme) === 'https';
        $remoteAddress = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: '';
        $trusted = $config['trusted_proxy']
            && in_array($remoteAddress, $config['trusted_proxy_ips'], true);
        return $direct || $configured || ($trusted && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}

final class LiteLoginRateLimiter
{
    private const LIMIT = 5;
    private const WINDOW = 900;

    private readonly string $file;

    public function __construct(
        string $directory,
        bool $trustedProxy = false,
        array $trustedProxyIps = [],
        string $clientIpHeader = 'HTTP_X_REAL_IP'
    )
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建登录限速目录');
        }
        $address = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: 'unknown';
        if ($trustedProxy && in_array($address, $trustedProxyIps, true)) {
            $candidate = filter_var($_SERVER[$clientIpHeader] ?? '', FILTER_VALIDATE_IP);
            if ($candidate !== false) {
                $address = $candidate;
            }
        }
        $this->file = rtrim($directory, '/') . '/' . hash('sha256', $address) . '.json';
    }

    public function reserveAttempt(): bool
    {
        if (is_link($this->file)) {
            throw new RuntimeException('登录限速状态路径无效');
        }
        $handle = fopen($this->file, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('无法锁定登录限速状态');
        }

        $contents = stream_get_contents($handle);
        $decoded = json_decode(is_string($contents) ? $contents : '', true);
        $cutoff = time() - self::WINDOW;
        $attempts = array_values(array_filter(is_array($decoded) ? $decoded : [], static fn ($time): bool => is_int($time) && $time > $cutoff));
        if (count($attempts) >= self::LIMIT) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return false;
        }

        $attempts[] = time();
        rewind($handle);
        ftruncate($handle, 0);
        $written = fwrite($handle, (string) json_encode($attempts));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        @chmod($this->file, 0600);
        if ($written === false) {
            throw new RuntimeException('无法写入登录限速状态');
        }
        return true;
    }

    public function clear(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }

}
