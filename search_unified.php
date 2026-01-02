<?php
declare(strict_types=1);

/**
 * Unified Search with Token Deduction
 * Supports both Normal and AI search modes
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/session.php';
require __DIR__ . '/internal_url.php';
require __DIR__ . '/admin_config.php';

$config = getConfigArray();

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

function maskEmail($email) {
    if (empty($email) || strpos($email, '@') === false) {
        return '***@***';
    }
    list($name, $domain) = explode('@', $email, 2);
    $nameLen = strlen($name);
    if ($nameLen <= 2) {
        $masked = str_repeat('*', $nameLen);
    } else {
        $masked = substr($name, 0, 2) . str_repeat('*', $nameLen - 2);
    }
    return $masked . '@' . $domain;
}

function maskMobile($mobile) {
    $digits = preg_replace('/[^0-9]/', '', $mobile);
    $len = strlen($digits);
    if ($len <= 4) {
        return str_repeat('*', $len);
    }
    $start = substr($digits, 0, 2);
    $end = substr($digits, -4);
    $middle = str_repeat('*', $len - 6);
    return $start . $middle . $end;
}

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

requireRole('employer');

$action = $_GET['action'] ?? 'search';

// get-costs is used by the dashboard and should not fail even if DB/token tables
// are missing. It only reads config.
if ($action === 'get-costs') {
    respondJson([
        'success' => true,
        'ai_costs' => $config['token_costs_ai'],
        'normal_costs' => $config['token_costs_normal'],
        'tokens_enabled' => true, // may be false if token tables are missing; checked below for search
    ]);
}

// DB is only required for actions that mutate/check balances (search/unmask/download)
require __DIR__ . '/db.php';

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

if (!tableExists($pdo, 'employer_tokens') || !tableExists($pdo, 'token_transactions') || !tableExists($pdo, 'unmasked_contacts')) {
    respondJson([
        'error' => 'Token system tables not installed',
        'message' => 'Import sql/patch_tokens_tables.sql to enable employer tokens, transactions, and unmask tracking.'
    ], 501);
}

// SEARCH WITH TOKEN DEDUCTION
if ($action === 'search') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondJson(['error' => 'Method not allowed'], 405);
    }
    
    $input = getJsonInput();
    $query = trim($input['query'] ?? '');
    $searchMode = trim($input['search_mode'] ?? 'ai'); // 'ai' or 'normal'
    
    if (empty($query)) {
        respondJson(['error' => 'Search query is required'], 400);
    }
    
    // Validate search mode
    if (!in_array($searchMode, ['ai', 'normal'])) {
        $searchMode = 'ai';
    }
    
    try {
        $userId = getCurrentUserId();
        
        // Get appropriate token cost based on search mode
        $tokenCosts = ($searchMode === 'ai') ? $config['token_costs_ai'] : $config['token_costs_normal'];
        $searchCost = (int)$tokenCosts['search'];
        
        // Check token balance
        $stmt = $pdo->prepare('SELECT token_balance FROM employer_tokens WHERE user_id = ?');
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            respondJson(['error' => 'Token account not found'], 404);
        }
        
        $balance = (int)$result['token_balance'];
        
        if ($balance < $searchCost) {
            respondJson([
                'error' => 'Insufficient tokens',
                'required' => $searchCost,
                'available' => $balance,
                'search_mode' => $searchMode,
                'message' => 'You need ' . $searchCost . ' tokens for ' . $searchMode . ' search. Please top up your account.'
            ], 400);
        }
        
        // Deduct tokens first
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare('
            UPDATE employer_tokens 
            SET token_balance = token_balance - ?, total_spent = total_spent + ? 
            WHERE user_id = ?
        ');
        $stmt->execute([$searchCost, $searchCost, $userId]);
        
        // Log transaction
        $stmt = $pdo->prepare('
            INSERT INTO token_transactions (user_id, transaction_type, tokens, description)
            VALUES (?, "search", ?, ?)
        ');
        $stmt->execute([$userId, -$searchCost, ucfirst($searchMode) . ' search: ' . substr($query, 0, 100)]);
        
        $pdo->commit();
        
        // Perform search based on mode
        $API_KEY = (string)$config['api_key'];
        $CURL_INSECURE = (bool)($config['curl_insecure_internal'] ?? false);
        
        $searchEndpoint = ($searchMode === 'ai') ? 'ai_candidate_search.php' : 'normal_search.php';
        
        $ch = curl_init(internal_api_url($searchEndpoint));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-KEY: ' . $API_KEY,
            ],
            CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
        ]);
        
        if ($CURL_INSECURE) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if (!$response) {
            respondJson(['error' => 'Search service unavailable'], 500);
        }
        
        $searchResults = json_decode($response, true);
        
        if (!is_array($searchResults)) {
            respondJson(['error' => 'Invalid search response'], 500);
        }
        
        // Get already unmasked contacts
        $stmt = $pdo->prepare('
            SELECT candidate_id, email_unmasked, mobile_unmasked, profile_downloaded
            FROM unmasked_contacts
            WHERE user_id = ?
        ');
        $stmt->execute([$userId]);
        $unmaskedMap = [];
        while ($row = $stmt->fetch()) {
            $unmaskedMap[$row['candidate_id']] = $row;
        }
        
        // Mask contacts in results
        if (isset($searchResults['results']) && is_array($searchResults['results'])) {
            foreach ($searchResults['results'] as &$result) {
                $candId = (string)($result['candidate_id'] ?? '');
                $isUnmasked = isset($unmaskedMap[$candId]);
                
                $profile = $result['profile'] ?? '';
                
                // Email
                if (preg_match('/EMAIL:\s*([^\r\n]+)/i', $profile, $m)) {
                    $email = trim($m[1]);
                    $result['email'] = ($isUnmasked && $unmaskedMap[$candId]['email_unmasked']) 
                        ? $email 
                        : maskEmail($email);
                    $result['email_masked'] = !($isUnmasked && $unmaskedMap[$candId]['email_unmasked']);
                }
                
                // Mobile
                if (preg_match('/(?:MOBILE|PHONE):\s*([^\r\n]+)/i', $profile, $m)) {
                    $mobile = trim($m[1]);
                    $result['mobile'] = ($isUnmasked && $unmaskedMap[$candId]['mobile_unmasked']) 
                        ? $mobile 
                        : maskMobile($mobile);
                    $result['mobile_masked'] = !($isUnmasked && $unmaskedMap[$candId]['mobile_unmasked']);
                }
                
                $result['profile_downloaded'] = $isUnmasked && ($unmaskedMap[$candId]['profile_downloaded'] ?? false);
            }
        }
        
        $searchResults['search_mode'] = $searchMode;
        $searchResults['tokens_deducted'] = $searchCost;
        $searchResults['new_balance'] = $balance - $searchCost;
        $searchResults['token_costs'] = $tokenCosts;
        
        respondJson($searchResults);
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respondJson(['error' => 'Search failed', 'details' => $e->getMessage()], 500);
    }
}

else {
    respondJson(['error' => 'Invalid action'], 400);
}
