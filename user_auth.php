<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/session.php';

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

function respond_json($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function request_data(): array
{
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

    if (!empty($_POST) && is_array($_POST)) {
        return $_POST;
    }

    // Fallback: parse raw as query-string (x-www-form-urlencoded without $_POST)
    $out = [];
    if (is_string($raw) && $raw !== '') {
        parse_str($raw, $out);
    }
    return is_array($out) ? $out : [];
}

function users_columns(PDO $pdo): array
{
    $cols = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM users');
    foreach ($stmt->fetchAll() as $row) {
        if (!empty($row['Field'])) {
            $cols[] = (string)$row['Field'];
        }
    }
    return $cols;
}

$action = $_GET['action'] ?? '';

/**
 * ME
 * - returns 401 when not logged in (frontend should treat as "not signed in")
 */
if ($action === 'me') {
    if (!isLoggedIn()) {
        respond_json(['error' => 'Unauthorized'], 401);
    }
    respond_json([
        'success' => true,
        'user' => [
            'id' => (int)getCurrentUserId(),
            'role' => (string)getCurrentUserRole(),
            'full_name' => (string)getCurrentUserName(),
            'name' => (string)getCurrentUserName(),
            'email' => (string)getCurrentUserEmail(),
        ],
    ]);
}

/**
 * LOGOUT
 */
if ($action === 'logout') {
    destroyUserSession();
    respond_json(['success' => true]);
}

/**
 * REGISTER
 */
if ($action === 'register') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond_json(['error' => 'Method not allowed'], 405);
    }

    $data = request_data();

    $email = strtolower(trim((string)($data['email'] ?? '')));
    $password = (string)($data['password'] ?? '');
    $role = strtolower(trim((string)($data['role'] ?? '')));

    $username = trim((string)($data['username'] ?? ''));
    $fullName = trim((string)($data['full_name'] ?? ''));

    if ($email === '' || $password === '' || $role === '') {
        respond_json(['error' => 'Missing required fields'], 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond_json(['error' => 'Invalid email'], 400);
    }

    if (!in_array($role, ['employer', 'jobseeker', 'admin'], true)) {
        respond_json(['error' => 'Invalid role'], 400);
    }

    if ($username === '') {
        $username = preg_replace('/@.*/', '', $email);
        $username = $username ?: ('user' . time());
    }

    try {
        $pdo = getDbConnection($config);

        // Block duplicates by email
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            respond_json(['error' => 'Email already registered'], 409);
        }

        $cols = users_columns($pdo);

        // Build insert dynamically to match real schema (shared DB may differ).
        $insertCols = [];
        $values = [];

        // Always required in our system
        if (in_array('username', $cols, true)) {
            $insertCols[] = 'username';
            $values[] = $username;
        }
        if (in_array('full_name', $cols, true)) {
            $insertCols[] = 'full_name';
            $values[] = ($fullName !== '' ? $fullName : $username);
        }

        $insertCols[] = 'email';
        $values[] = $email;

        $insertCols[] = 'password';
        $values[] = password_hash($password, PASSWORD_BCRYPT);

        if (in_array('role', $cols, true)) {
            $insertCols[] = 'role';
            $values[] = $role;
        }

        $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
        $sql = 'INSERT INTO users (' . implode(',', $insertCols) . ') VALUES (' . $placeholders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        $userId = (int)$pdo->lastInsertId();
        setUserSession($userId, $role, ($fullName !== '' ? $fullName : $username), $email);// Initialize employer token wallet when table exists (shared DBs may vary).
if ($role === 'employer') {
    try {
        $tablesStmt = $pdo->query("SHOW TABLES LIKE 'employer_tokens'");
        $hasTokensTable = (bool)$tablesStmt->fetchColumn();
        if ($hasTokensTable) {
            $init = (int)($config['initial_tokens'] ?? 1000);
            $pdo->prepare("
                INSERT INTO employer_tokens (user_id, token_balance, total_earned, total_spent)
                VALUES (?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE user_id = user_id
            ")->execute([$userId, $init, $init]);

            // Optional: log initial grant when token_transactions exists.
            $txnStmt = $pdo->query("SHOW TABLES LIKE 'token_transactions'");
            $hasTxnTable = (bool)$txnStmt->fetchColumn();
            if ($hasTxnTable) {
                $pdo->prepare("
                    INSERT INTO token_transactions (user_id, transaction_type, tokens, description)
                    VALUES (?, 'initial', ?, 'Initial employer tokens')
                ")->execute([$userId, $init]);
            }
        }
    } catch (Exception $e) {
        // Non-fatal: registration should still succeed.
    }
}

        respond_json([
            'success' => true,
            'user' => [
                'id' => $userId,
                'username' => $username,
                'full_name' => ($fullName !== '' ? $fullName : $username),
                'email' => $email,
                'role' => $role,
            ],
        ]);
    } catch (Exception $e) {
        respond_json(['error' => 'Registration failed', 'details' => $e->getMessage()], 500);
    }
}

/**
 * LOGIN
 */
if ($action === 'login') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond_json(['error' => 'Method not allowed'], 405);
    }

    $data = request_data();
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $password = (string)($data['password'] ?? '');

    if ($email === '' || $password === '') {
        respond_json(['error' => 'Missing required fields'], 400);
    }

    try {
        $pdo = getDbConnection($config);

        $cols = users_columns($pdo);
        $select = 'SELECT id, email, password';
        if (in_array('full_name', $cols, true)) {
            $select .= ', full_name';
        }
        if (in_array('username', $cols, true)) {
            $select .= ', username';
        }
        if (in_array('role', $cols, true)) {
            $select .= ', role';
        }
        $select .= ' FROM users WHERE email = ? LIMIT 1';

        $stmt = $pdo->prepare($select);
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !isset($user['password']) || !password_verify($password, (string)$user['password'])) {
            respond_json(['error' => 'Invalid email or password'], 401);
        }

        // Normalize role values across older DBs / admin-panel mappings
        $role = isset($user['role']) ? (string)$user['role'] : 'jobseeker';
        $role = normalizeRole($role);
        $name = '';
        if (isset($user['full_name']) && (string)$user['full_name'] !== '') {
            $name = (string)$user['full_name'];
        } elseif (isset($user['username'])) {
            $name = (string)$user['username'];
        } else {
            $name = $email;
        }

        setUserSession((int)$user['id'], $role, $name, $email);

        respond_json([
            'success' => true,
            'user' => [
                'id' => (int)$user['id'],
                'email' => $email,
                'role' => $role,
                'full_name' => $name,
                'name' => $name,
            ],
        ]);
    } catch (Exception $e) {
        respond_json(['error' => 'Login failed', 'details' => $e->getMessage()], 500);
    }
}

respond_json(['error' => 'Invalid action'], 400);
