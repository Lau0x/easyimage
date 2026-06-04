<?php

function easyimage_path_is_inside($path, $base)
{
    $realPath = realpath($path);
    $realBase = realpath($base);

    if ($realPath === false || $realBase === false) {
        return false;
    }

    $realPath = str_replace('\\', '/', $realPath);
    $realBase = rtrim(str_replace('\\', '/', $realBase), '/') . '/';
    $pathForCompare = is_dir($realPath) ? rtrim($realPath, '/') . '/' : $realPath;

    return strpos($pathForCompare, $realBase) === 0;
}

function easyimage_upload_base()
{
    global $config;
    return APP_ROOT . $config['path'];
}

function easyimage_candidate_upload_paths($path)
{
    global $config;

    $path = strip_tags((string)$path);
    $parsedPath = parse_url($path, PHP_URL_PATH);
    $path = ($parsedPath === null || $parsedPath === false) ? '' : urldecode(trim($parsedPath));
    $path = str_replace('\\', '/', $path);
    $base = easyimage_upload_base();
    $candidates = array();

    if (strpos($path, APP_ROOT) === 0) {
        $candidates[] = $path;
    }

    if ($path !== '' && $path[0] === '/') {
        $candidates[] = APP_ROOT . $path;
    }

    $candidates[] = APP_ROOT . '/' . ltrim($path, '/');
    $candidates[] = rtrim($base, '/') . '/' . ltrim($path, '/');

    if (strpos($path, $config['path']) === 0) {
        $candidates[] = APP_ROOT . '/' . ltrim($path, '/');
    }

    return array_unique($candidates);
}

function easyimage_resolve_upload_file($path)
{
    foreach (easyimage_candidate_upload_paths($path) as $candidate) {
        if (is_file($candidate) && easyimage_path_is_inside($candidate, easyimage_upload_base())) {
            return realpath($candidate);
        }
    }

    return false;
}

function easyimage_resolve_upload_dir($path)
{
    foreach (easyimage_candidate_upload_paths($path) as $candidate) {
        if (is_dir($candidate) && easyimage_path_is_inside($candidate, easyimage_upload_base())) {
            return realpath($candidate);
        }
    }

    return false;
}

function easyimage_upload_relative_path($path)
{
    if (!easyimage_path_is_inside($path, easyimage_upload_base())) {
        return false;
    }

    $realPath = str_replace('\\', '/', realpath($path));
    $realBase = rtrim(str_replace('\\', '/', realpath(easyimage_upload_base())), '/');

    return ltrim(substr($realPath, strlen($realBase)), '/');
}
