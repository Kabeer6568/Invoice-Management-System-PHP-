<?php

if (strtolower(getenv('APP_ENV') ?: 'development') === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

function app_base_path(): string
{
    $configuredPath = getenv('APP_BASE_PATH');
    if ($configuredPath !== false && trim($configuredPath) !== '') {
        $path = '/' . trim(str_replace('\\', '/', $configuredPath), '/');
        return $path === '/' ? '' : $path;
    }

    $configuredUrl = getenv('APP_URL');
    if ($configuredUrl !== false && trim($configuredUrl) !== '') {
        $urlPath = parse_url($configuredUrl, PHP_URL_PATH);
        if (is_string($urlPath) && $urlPath !== '/') {
            return '/' . trim($urlPath, '/');
        }
        return '';
    }

    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $projectRoot = realpath(dirname(__DIR__));
    if ($documentRoot === false || $projectRoot === false) {
        return '';
    }

    $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
    $projectRoot = str_replace('\\', '/', $projectRoot);
    $documentRootPrefix = $documentRoot . '/';

    if (stripos($projectRoot, $documentRootPrefix) === 0) {
        return '/' . ltrim(substr($projectRoot, strlen($documentRootPrefix)), '/');
    }

    return '';
}

function app_url(string $path = ''): string
{
    $basePath = app_base_path();
    $path = ltrim($path, '/');

    if ($path === '') {
        return $basePath === '' ? '/' : $basePath . '/';
    }

    return $basePath . '/' . $path;
}

function app_absolute_url(string $path = ''): string
{
    $configuredUrl = getenv('APP_URL');
    if ($configuredUrl !== false && trim($configuredUrl) !== '') {
        $configuredUrl = rtrim(trim($configuredUrl), '/');
        $parts = parse_url($configuredUrl);
        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('APP_URL must be an absolute HTTP or HTTPS URL.');
        }

        return $configuredUrl . '/' . ltrim($path, '/');
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/\A[a-zA-Z0-9.-]+(?::[0-9]{1,5})?\z/', $host)) {
        throw new RuntimeException('Set APP_URL to the public application URL.');
    }

    $isHttps = isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $isHttps ? 'https' : 'http';

    return $scheme . '://' . $host . app_url($path);
}
