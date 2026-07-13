<?php

declare(strict_types=1);

return [
    'base_url' => 'https://images.example.com',
    'app_path' => '/lite',
    'timezone' => 'Asia/Shanghai',
    'username' => 'admin',
    'password' => 'REPLACE_WITH_PASSWORD_HASH_OUTPUT',
    'max_size' => 10 * 1024 * 1024,
    'max_files' => 10,
    'trusted_proxy' => false,
    'trusted_proxy_ips' => [],
    'client_ip_header' => 'HTTP_X_REAL_IP',
    'api_enabled' => false,
    'api_tokens' => [],
    'allow_legacy_api_tokens' => false,
];
