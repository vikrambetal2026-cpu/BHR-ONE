<?php
declare(strict_types=1);

/**
 * Ensure an employer has a token wallet row.
 * Shared databases may miss rows even if table exists.
 */
function ensureTokenAccount(PDO $pdo, int $userId, array $config): void {
    $stmt = $pdo->prepare('SELECT 1 FROM employer_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn()) {
        return;
    }
    $init = (int)($config['initial_tokens'] ?? 1000);
    $pdo->prepare("
        INSERT INTO employer_tokens (user_id, token_balance, total_earned, total_spent)
        VALUES (?, ?, ?, 0)
    ")->execute([$userId, $init, $init]);
    try {
        $pdo->prepare("
            INSERT INTO token_transactions (user_id, transaction_type, tokens, description)
            VALUES (?, 'initial', ?, 'Initial employer tokens (auto-created)')
        ")->execute([$userId, $init]);
    } catch (Exception $e) {
        // ignore if token_transactions doesn't exist
    }
}

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/session.php';
require __DIR__ . '/db.php';

$config = require __DIR__ . '/config.php';

function respondJson($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function getJsonInput() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

requireRole('employer');

// Shared deployments sometimes skip token tables. Avoid crashing the whole UI.
function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

if (!tableExists($pdo, 'employer_tokens')) {
    // Tokens feature not installed yet.
    respondJson([
        'success' => true,
        'tokens_enabled' => false,
        'token_balance' => 0,
        'total_earned' => 0,
        'total_spent' => 0,
        'message' => 'Token tables not installed. Import sql/patch_tokens_tables.sql to enable tokens.'
    ]);
}

$action = $_GET['action'] ?? 'balance';

// GET TOKEN BALANCE
if ($action === 'balance') {
    try {
        $userId = getCurrentUserId();
        
        ensureTokenAccount($pdo, $userId, $config);
        $stmt = $pdo->prepare('SELECT token_balance, total_earned, total_spent FROM employer_tokens WHERE user_id = ?');
        $stmt->execute([$userId]);
        $tokens = $stmt->fetch();
        
        if (!$tokens) {
            respondJson(['error' => 'Token account not found'], 404);
        }

        // Backwards compatible fields (some JS expects token_balance / total_* at top-level)
        $balance = (int)$tokens['token_balance'];
        $earned  = (int)$tokens['total_earned'];
        $spent   = (int)$tokens['total_spent'];
        respondJson([
            'success' => true,
            'tokens_enabled' => true,
            'token_balance' => $balance,
            'total_earned' => $earned,
            'total_spent' => $spent,
            'tokens' => [
                'balance' => $balance,
                'total_earned' => $earned,
                'total_spent' => $spent,
            ]
        ]);
        
    } catch (Exception $e) {
        respondJson(['error' => 'Failed to fetch token balance', 'details' => $e->getMessage()], 500);
    }
}

// GET TOKEN TRANSACTION HISTORY
else if ($action === 'history') {
    try {
        $userId = getCurrentUserId();
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        $stmt = $pdo->prepare('
            SELECT * FROM token_transactions 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([$userId, $limit, $offset]);
        $transactions = $stmt->fetchAll();
        
        $formatted = [];
        foreach ($transactions as $tx) {
            $formatted[] = [
                'id' => (int)$tx['id'],
                'type' => $tx['transaction_type'],
                'tokens' => (int)$tx['tokens'],
                'candidate_id' => $tx['candidate_id'],
                'description' => $tx['description'],
                'created_at' => $tx['created_at'],
            ];
        }
        
        respondJson(['success' => true, 'transactions' => $formatted]);
        
    } catch (Exception $e) {
        respondJson(['error' => 'Failed to fetch transaction history', 'details' => $e->getMessage()], 500);
    }
}

// DEDUCT TOKENS
else if ($action === 'deduct') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondJson(['error' => 'Method not allowed'], 405);
    }
    
    $input = getJsonInput();
    $type = trim($input['type'] ?? '');
    $candidateId = trim($input['candidate_id'] ?? '');
    $description = trim($input['description'] ?? '');
    
    $validTypes = ['search', 'unmask_email', 'unmask_mobile', 'download_profile'];
    if (!in_array($type, $validTypes)) {
        respondJson(['error' => 'Invalid transaction type'], 400);
    }
    
    $tokenCosts = $config['token_costs'];
    $cost = (int)$tokenCosts[$type];
    
    try {
        $userId = getCurrentUserId();
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Get current balance with lock
        $stmt = $pdo->prepare('SELECT token_balance FROM employer_tokens WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            $pdo->rollBack();
            respondJson(['error' => 'Token account not found'], 404);
        }
        
        $currentBalance = (int)$result['token_balance'];
        
        if ($currentBalance < $cost) {
            $pdo->rollBack();
            respondJson(['error' => 'Insufficient tokens', 'required' => $cost, 'available' => $currentBalance], 400);
        }
        
        // Deduct tokens
        $newBalance = $currentBalance - $cost;
        $stmt = $pdo->prepare('
            UPDATE employer_tokens 
            SET token_balance = ?, total_spent = total_spent + ? 
            WHERE user_id = ?
        ');
        $stmt->execute([$newBalance, $cost, $userId]);
        
        // Log transaction
        $stmt = $pdo->prepare('
            INSERT INTO token_transactions (user_id, transaction_type, tokens, candidate_id, description)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$userId, $type, -$cost, $candidateId, $description]);
        
        $pdo->commit();
        
        respondJson([
            'success' => true,
            'message' => 'Tokens deducted successfully',
            'tokens_deducted' => $cost,
            'new_balance' => $newBalance
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respondJson(['error' => 'Failed to deduct tokens', 'details' => $e->getMessage()], 500);
    }
}

// CHECK IF ALREADY DEDUCTED (for unmask/download)
else if ($action === 'check-access') {
    $candidateId = trim($_GET['candidate_id'] ?? '');
    
    if (empty($candidateId)) {
        respondJson(['error' => 'Candidate ID is required'], 400);
    }
    
    try {
        $userId = getCurrentUserId();
        
        $stmt = $pdo->prepare('
            SELECT email_unmasked, mobile_unmasked, profile_downloaded 
            FROM unmasked_contacts 
            WHERE user_id = ? AND candidate_id = ?
        ');
        $stmt->execute([$userId, $candidateId]);
        $access = $stmt->fetch();
        
        if (!$access) {
            respondJson([
                'success' => true,
                'access' => [
                    'email_unmasked' => false,
                    'mobile_unmasked' => false,
                    'profile_downloaded' => false,
                ]
            ]);
        } else {
            respondJson([
                'success' => true,
                'access' => [
                    'email_unmasked' => (bool)$access['email_unmasked'],
                    'mobile_unmasked' => (bool)$access['mobile_unmasked'],
                    'profile_downloaded' => (bool)$access['profile_downloaded'],
                ]
            ]);
        }
        
    } catch (Exception $e) {
        respondJson(['error' => 'Failed to check access', 'details' => $e->getMessage()], 500);
    }
}

else {
    respondJson(['error' => 'Invalid action'], 400);
}
