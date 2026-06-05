<?php

$enabled = strtolower(trim((string)getenv('EASYIMAGE_HOTLINK')));
$enabledValues = array('1', 'true', 'yes', 'on');
$confFile = '/etc/apache2/conf-enabled/easyimage-hotlink.conf';

if (!in_array($enabled, $enabledValues, true)) {
    @unlink($confFile);
    exit(0);
}

$allowEmpty = strtolower(trim((string)getenv('EASYIMAGE_HOTLINK_ALLOW_EMPTY')));
$allowEmpty = $allowEmpty === '' || in_array($allowEmpty, $enabledValues, true);
$domainValue = (string)getenv('EASYIMAGE_HOTLINK_DOMAINS');
$domains = preg_split('/\s*,\s*/', $domainValue, -1, PREG_SPLIT_NO_EMPTY);
$patterns = array();

foreach ($domains as $domain) {
    $domain = trim($domain);
    $url = strpos($domain, '://') === false ? 'http://' . $domain : $domain;
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        continue;
    }

    $host = strtolower(preg_replace('/^\*\./', '', $host));
    if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
        continue;
    }

    $patterns[$host] = preg_quote($host, '#');
}

$lines = array(
    '<Directory "/var/www/html/i">',
    '    RewriteEngine On',
    '    RewriteCond %{REQUEST_FILENAME} -f',
    '    RewriteCond %{REQUEST_FILENAME} \.(?:jpe?g|png|gif|webp|bmp|ico|jfif|tiff?|tga)$ [NC]',
);

if ($allowEmpty) {
    $lines[] = '    RewriteCond %{HTTP_REFERER} !^$';
}

foreach ($patterns as $pattern) {
    $lines[] = '    RewriteCond %{HTTP_REFERER} !^https?://([^/]+\.)?' . $pattern . '(:[0-9]+)?(/|$) [NC]';
}

$lines[] = '    RewriteRule ^ - [F]';
$lines[] = '</Directory>';

file_put_contents($confFile, implode(PHP_EOL, $lines) . PHP_EOL);

if (empty($patterns)) {
    fwrite(STDERR, "PicLite hotlink protection enabled without allowed domains.\n");
} else {
    fwrite(STDOUT, "PicLite hotlink protection enabled for: " . implode(', ', array_keys($patterns)) . "\n");
}
