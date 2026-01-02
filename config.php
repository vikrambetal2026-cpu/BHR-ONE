<?php
declare(strict_types=1);

/**
 * Runtime configuration loader.
 *
 * SECURITY NOTE:
 * Do not hardcode credentials/API keys in this file. Use:
 * - Environment variables (preferred), or
 * - Admin Dashboard settings (admin_settings.json) created/updated via admin panel.
 */

require_once __DIR__ . '/admin_config.php';

$cfg = getConfigArray();

/**
 * Environment variable overrides (optional).
 * Any env var set will override admin_settings.json.
 */
$envMap = [
    'DB_HOST' => ['db','host'],
    'DB_NAME' => ['db','name'],
    'DB_USER' => ['db','user'],
    'DB_PASS' => ['db','pass'],
    'DB_CHARSET' => ['db','charset'],
    'API_KEY' => ['api_key'],
    'HF_TOKEN' => ['hf_token'],
    'RZP_KEY_ID' => ['razorpay','key_id'],
    'RZP_KEY_SECRET' => ['razorpay','key_secret'],
    'RZP_WEBHOOK_SECRET' => ['razorpay','webhook_secret'],
    'CURL_INSECURE_INTERNAL' => ['curl_insecure_internal'],
    'CURL_INSECURE_EXTERNAL' => ['curl_insecure_external'],
    'FORCE_HTTP' => ['site','force_http'],
];

foreach ($envMap as $env => $path) {
    $v = getenv($env);
    if ($v === false || $v === '') continue;

    // Convert booleans for known flags
    if (in_array($env, ['CURL_INSECURE_INTERNAL','CURL_INSECURE_EXTERNAL','FORCE_HTTP'], true)) {
        $v = ((string)$v === '1' || strtolower((string)$v) === 'true') ? true : false;
    }

    // Set nested
    $ref =& $cfg;
    for ($i = 0; $i < count($path) - 1; $i++) {
        $k = $path[$i];
        if (!isset($ref[$k]) || !is_array($ref[$k])) $ref[$k] = [];
        $ref =& $ref[$k];
    }
    $ref[$path[count($path)-1]] = $v;
}


// Optional: local overrides (no write permissions needed).
$localPath = __DIR__ . '/config.local.php';
if (file_exists($localPath)) {
    $local = require $localPath;
    if (is_array($local)) {
        $cfg = array_replace_recursive($cfg, $local);
    }
}

return $cfg;
