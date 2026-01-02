<?php
/**
 * File: api/session.php
 * Purpose: Robust PHP session init for subfolder deployments (PHP 7.3+).
 *
 * Key: ensure cookies work over HTTPS + across /bhrjp/*, and allow fetch() with credentials.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

// FORCE HTTP cookies (per requirement: https must not be used; SSL may be invalid)
// If we ever set a Secure cookie and the browser is on http://, the session will vanish.
$https = false;
$basePath = '/';
if (!empty($_SERVER['SCRIPT_NAME'])) {
    // From /bhrjp/api/foo.php -> /bhrjp
    $basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/';
    if ($basePath === '//' || $basePath === '/./') {
        $basePath = '/';
    }
}

session_name('bhrjp_sess');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => $basePath,
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

// Helper functions for session management
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
}

function getCurrentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function getCurrentUserRole(): string {
    return normalizeRole((string)($_SESSION['user_role'] ?? ''));
}

function getCurrentUserName(): string {
    return (string)($_SESSION['user_name'] ?? '');
}

function getCurrentUserEmail(): string {
    return (string)($_SESSION['user_email'] ?? '');
}

function setUserSession(int $userId, string $role, string $name, string $email): void {
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_role'] = normalizeRole($role);
    $_SESSION['user_name'] = $name;
    $_SESSION['user_email'] = $email;
}

function destroyUserSession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

function requireRole(string $requiredRole): void {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized - Please login']);
        exit;
    }
    $currentRole = normalizeRole(getCurrentUserRole());
    $requiredRole = normalizeRole($requiredRole);
    if ($currentRole !== $requiredRole) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden - Invalid role']);
        exit;
    }
}

/**
 * Normalize role values across older DBs / admin-panel mappings.
 * We always work with canonical roles: jobseeker | employer | admin
 */
function normalizeRole(string $role): string {
    $r = strtolower(trim($role));
    // Common synonyms / legacy values
    if ($r === 'job_seeker' || $r === 'job seeker' || $r === 'candidate' || $r === 'seeker') {
        return 'jobseeker';
    }
    if ($r === 'employer' || $r === 'company' || $r === 'recruiter' || $r === 'hr') {
        return 'employer';
    }
    if ($r === 'administrator') {
        return 'admin';
    }
    return $r;
}

function getDbConnection(array $config): PDO {
    $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}";
    return new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}
