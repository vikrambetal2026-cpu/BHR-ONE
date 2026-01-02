<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/session.php';
require __DIR__ . '/admin_config.php';

header('Content-Type: application/json; charset=utf-8');

function respond_json($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function request_data(): array {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (strtoupper($method) !== 'POST') {
        return [];
    }
    
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $raw = file_get_contents('php://input');
    
    if (stripos($contentType, 'application/json') !== false) {
        $decoded = json_decode($raw ?: '', true);
        return is_array($decoded) ? $decoded : [];
    }
    
    return $_POST ?: [];
}

function isAdminLoggedIn(): bool {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireAdmin(): void {
    if (!isAdminLoggedIn()) {
        respond_json(['error' => 'Admin authentication required'], 401);
    }
}

$action = $_GET['action'] ?? '';

// ADMIN LOGIN
if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond_json(['error' => 'Method not allowed'], 405);
    }
    
    $data = request_data();
    $username = trim((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');
    
    if ($username === '' || $password === '') {
        respond_json(['error' => 'Username and password are required'], 400);
    }
    
    $config = loadAdminConfig();
    $adminUser = $config['admin']['username'] ?? '';
    $adminPassHash = $config['admin']['password'] ?? '';
    $firstLogin = $config['admin']['first_login'] ?? false;
    
    // For first login, also accept plain password
    $passwordValid = false;
    if ($firstLogin && $password === 'ChangeMe@123' && $username === 'bhradmin') {
        $passwordValid = true;
    } elseif (password_verify($password, $adminPassHash)) {
        $passwordValid = true;
    }
    
    if ($username !== $adminUser || !$passwordValid) {
        respond_json(['error' => 'Invalid credentials'], 401);
    }
    
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_username'] = $username;
    
    respond_json([
        'success' => true,
        'first_login' => $firstLogin,
        'message' => 'Admin login successful'
    ]);
}

// ADMIN LOGOUT
if ($action === 'logout') {
    unset($_SESSION['admin_logged_in']);
    unset($_SESSION['admin_username']);
    respond_json(['success' => true]);
}

// CHECK ADMIN STATUS
if ($action === 'me') {
    if (!isAdminLoggedIn()) {
        respond_json(['error' => 'Not authenticated'], 401);
    }
    
    $config = loadAdminConfig();
    respond_json([
        'success' => true,
        'admin' => [
            'username' => $_SESSION['admin_username'] ?? '',
            'first_login' => $config['admin']['first_login'] ?? false,
        ]
    ]);
}

// CHANGE PASSWORD
if ($action === 'change-password') {
    requireAdmin();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond_json(['error' => 'Method not allowed'], 405);
    }
    
    $data = request_data();
    $currentPassword = (string)($data['current_password'] ?? '');
    $newPassword = (string)($data['new_password'] ?? '');
    
    if ($newPassword === '' || strlen($newPassword) < 6) {
        respond_json(['error' => 'New password must be at least 6 characters'], 400);
    }
    
    $config = loadAdminConfig();
    $firstLogin = $config['admin']['first_login'] ?? false;
    
    // For first login, current password check is optional
    if (!$firstLogin) {
        if (!password_verify($currentPassword, $config['admin']['password'])) {
            respond_json(['error' => 'Current password is incorrect'], 400);
        }
    }
    
    $config['admin']['password'] = password_hash($newPassword, PASSWORD_BCRYPT);
    $config['admin']['first_login'] = false;
    
    if (saveAdminConfig($config)) {
        respond_json(['success' => true, 'message' => 'Password changed successfully']);
    } else {
        respond_json(['error' => 'Failed to save configuration'], 500);
    }
}

// GET ALL CONFIG
if ($action === 'get-config') {
    requireAdmin();
    
    $config = loadAdminConfig();
    
    // Remove sensitive password hash
    unset($config['admin']['password']);
    
    respond_json(['success' => true, 'config' => $config]);
}

// UPDATE CONFIG SECTION
if ($action === 'update-config') {
    requireAdmin();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond_json(['error' => 'Method not allowed'], 405);
    }
    
    $data = request_data();
    $section = (string)($data['section'] ?? '');
    $values = $data['values'] ?? [];
    
    if ($section === '' || !is_array($values)) {
        respond_json(['error' => 'Section and values are required'], 400);
    }
    
    // Prevent changing admin password through this endpoint
    if ($section === 'admin') {
        unset($values['password']);
    }
    
    if (updateAdminConfig($section, $values)) {
        respond_json(['success' => true, 'message' => 'Configuration updated']);
    } else {
        respond_json(['error' => 'Failed to save configuration'], 500);
    }
}

// UPDATE MULTIPLE SECTIONS
if ($action === 'update-all') {
    requireAdmin();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond_json(['error' => 'Method not allowed'], 405);
    }
    
    $data = request_data();
    $config = loadAdminConfig();
    
    // Update allowed sections
    $allowedSections = ['db', 'razorpay', 'token_costs_ai', 'token_costs_normal', 'initial_tokens', 'free_tier', 'site', 'api_key', 'hf_token'];
    
    foreach ($allowedSections as $section) {
        if (isset($data[$section])) {
            if (is_array($data[$section])) {
                $config[$section] = array_merge($config[$section] ?? [], $data[$section]);
            } else {
                $config[$section] = $data[$section];
            }
        }
    }
    
    if (saveAdminConfig($config)) {
        respond_json(['success' => true, 'message' => 'All configurations updated']);
    } else {
        respond_json(['error' => 'Failed to save configuration'], 500);
    }
}

// TEST DATABASE CONNECTION
if ($action === 'test-db') {
    requireAdmin();
    
    $data = request_data();
    $host = (string)($data['host'] ?? '');
    $name = (string)($data['name'] ?? '');
    $user = (string)($data['user'] ?? '');
    $pass = (string)($data['pass'] ?? '');
    
    if ($host === '' || $name === '' || $user === '') {
        respond_json(['error' => 'Database credentials are required'], 400);
    }
    
    try {
        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        
        respond_json(['success' => true, 'message' => 'Database connection successful']);
    } catch (Exception $e) {
        respond_json(['error' => 'Connection failed: ' . $e->getMessage()], 400);
    }
}

respond_json(['error' => 'Invalid action'], 400);
