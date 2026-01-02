<?php
declare(strict_types=1);

/**
 * Internal URL Helper
 * Constructs internal API URLs.
 *
 * IMPORTANT:
 * - By default we FORCE http:// to avoid HTTPS breaking deployments where SSL is not configured.
 * - You can enable HTTPS URL generation by setting environment variable ALLOW_HTTPS_URLS=1
 *   and (optionally) FORCE_HTTP=0.
 */

function internal_api_url(string $endpoint): string {
    $allowHttps = (string)getenv('ALLOW_HTTPS_URLS') === '1';
    $forceHttpEnv = getenv('FORCE_HTTP');
    $forceHttp = $forceHttpEnv === false ? true : ((string)$forceHttpEnv !== '0');

    // Detect current protocol only when allowed and not forced to http
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    $protocol = ($allowHttps && !$forceHttp && $isHttps) ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    $basePath = rtrim($basePath, '/');

    return $protocol . '://' . $host . $basePath . '/' . ltrim($endpoint, '/');
}
