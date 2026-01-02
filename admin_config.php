<?php
declare(strict_types=1);

/**
 * Admin Configuration Management
 * Stores SuperAdmin settings in a JSON file for easy management
 */

define('ADMIN_CONFIG_FILE', __DIR__ . '/admin_settings.json');

function getDefaultAdminConfig(): array {
    return [
        // SuperAdmin credentials
        'admin' => [
            'username' => 'bhradmin',
            // Default password: change on first login
            'password' => password_hash('ChangeMe@123', PASSWORD_BCRYPT),
            'first_login' => true,
        ],

        // Database settings (set via Admin Dashboard or environment variables)
        'db' => [
            'host' => getenv('DB_HOST') ?: 'localhost',
            'name' => getenv('DB_NAME') ?: 'job_portal',
            'user' => getenv('DB_USER') ?: 'root',
            'pass' => getenv('DB_PASS') ?: '',
            'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
        ],

        // Internal API key used between endpoints (set in env or Admin Dashboard)
        'api_key' => getenv('API_KEY') ?: 'CHANGE_ME_API_KEY',

        // HuggingFace token (set in env or Admin Dashboard)
        'hf_token' => getenv('HF_TOKEN') ?: '',

        // Razorpay settings (set in env or Admin Dashboard)
        'razorpay' => [
            'key_id' => getenv('RZP_KEY_ID') ?: '',
            'key_secret' => getenv('RZP_KEY_SECRET') ?: '',
            'webhook_secret' => getenv('RZP_WEBHOOK_SECRET') ?: '',
        ],

        // Token pricing - AI Search
        'token_costs_ai' => [
            'search' => 150,
            'unmask_email' => 25,
            'unmask_mobile' => 50,
            'download_profile' => 100,
        ],

        // Token pricing - Normal Search
        'token_costs_normal' => [
            'search' => 50,
            'unmask_email' => 15,
            'unmask_mobile' => 30,
            'download_profile' => 75,
        ],

        // Initial tokens for new employers
        'initial_tokens' => 500,

        // Free tier limits (daily)
        'free_tier' => [
            'daily_unmasks' => 3,
            'daily_downloads' => 1,
        ],

        // cURL SSL verification toggles
        // NOTE: Disabling SSL verification is NOT recommended for production.
        'curl_insecure_internal' => getenv('CURL_INSECURE_INTERNAL') ? ((string)getenv('CURL_INSECURE_INTERNAL') === '1') : true,
        'curl_insecure_external' => getenv('CURL_INSECURE_EXTERNAL') ? ((string)getenv('CURL_INSECURE_EXTERNAL') === '1') : true,

        // Site behavior
        'site' => [
            // Force internal URL generation to HTTP (prevents HTTPS mixed-content issues)
            'force_http' => getenv('FORCE_HTTP') ? ((string)getenv('FORCE_HTTP') === '1') : true,
        ],

    ];
}
function loadAdminConfig(): array {
    if (!file_exists(ADMIN_CONFIG_FILE)) {
        $config = getDefaultAdminConfig();
        saveAdminConfig($config);
        return $config;
    }
    
    $json = file_get_contents(ADMIN_CONFIG_FILE);
    $config = json_decode($json, true);
    
    if (!is_array($config)) {
        return getDefaultAdminConfig();
    }
    
    // Merge with defaults for any missing keys
    return array_replace_recursive(getDefaultAdminConfig(), $config);
}

function saveAdminConfig(array $config): bool {
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents(ADMIN_CONFIG_FILE, $json) !== false;
}

function updateAdminConfig(string $section, array $values): bool {
    $config = loadAdminConfig();
    
    if (isset($config[$section]) && is_array($config[$section])) {
        $config[$section] = array_merge($config[$section], $values);
    } else {
        $config[$section] = $values;
    }
    
    return saveAdminConfig($config);
}

function getAdminConfigValue(string $section, string $key = null) {
    $config = loadAdminConfig();
    
    if ($key === null) {
        return $config[$section] ?? null;
    }
    
    return $config[$section][$key] ?? null;
}

// Generate config.php compatible array
function getConfigArray(): array {
    $admin = loadAdminConfig();
    
    return [
        'db' => $admin['db'],
        'api_key' => $admin['api_key'],
        'hf_token' => $admin['hf_token'],
        'razorpay' => $admin['razorpay'],
        'token_costs' => $admin['token_costs_ai'], // Default to AI costs
        'token_costs_ai' => $admin['token_costs_ai'],
        'token_costs_normal' => $admin['token_costs_normal'],
        'initial_tokens' => $admin['initial_tokens'],
        'free_tier' => $admin['free_tier'],
        'curl_insecure_internal' => $admin['curl_insecure_internal'],
        'site' => $admin['site'],
    ];
}
