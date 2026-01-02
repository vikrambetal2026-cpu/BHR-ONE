<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/session.php';
require __DIR__ . '/db.php';
require __DIR__ . '/internal_url.php';

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

// SEARCH WITH TOKEN DEDUCTION
if ($action === 'search') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondJson(['error' => 'Method not allowed'], 405);
    }
    
    $input = getJsonInput();
    $query = trim($input['query'] ?? '');
    
    if (empty($query)) {
        respondJson(['error' => 'Search query is required'], 400);
    }
    
    try {
        $userId = getCurrentUserId();
        $searchCost = (int)$config['token_costs']['search'];
        
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
                'message' => 'You need ' . $searchCost . ' tokens to perform a search. Please top up your account.'
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
        $stmt->execute([$userId, -$searchCost, 'Candidate search: ' . substr($query, 0, 100)]);
        
        $pdo->commit();
        
        // Now perform the search
        // Call existing AI candidate search
        $API_KEY = (string)$config['api_key'];
        $CURL_INSECURE = (bool)($config['curl_insecure_internal'] ?? false);
        
        $ch = curl_init(internal_api_url('ai_candidate_search.php'));
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
        
        // Also search local job applications
        $localResults = [];
        try {
            $stmt = $pdo->prepare('
                SELECT DISTINCT
                    CONCAT("local_", ja.id) as candidate_id,
                    u.full_name,
                    u.email,
                    u.phone,
                    ja.resume_text as profile,
                    ja.applied_at
                FROM job_applications ja
                LEFT JOIN users u ON ja.jobseeker_id = u.id
                LEFT JOIN jobs j ON ja.job_id = j.id
                WHERE j.employer_id = ? AND ja.resume_text IS NOT NULL
                AND (ja.resume_text LIKE ? OR u.full_name LIKE ?)
                ORDER BY ja.applied_at DESC
                LIMIT 10
            ');
            $searchPattern = '%' . $query . '%';
            $stmt->execute([$userId, $searchPattern, $searchPattern]);
            $localResults = $stmt->fetchAll();
        } catch (Exception $e) {
            // Continue even if local search fails
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
        
        // Mask contacts in MySQL results
        if (isset($searchResults['results']) && is_array($searchResults['results'])) {
            foreach ($searchResults['results'] as &$result) {
                $candId = (string)($result['candidate_id'] ?? '');
                $isUnmasked = isset($unmaskedMap[$candId]);
                
                // Extract email and phone from profile
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
                
                $result['profile_downloaded'] = $isUnmasked && $unmaskedMap[$candId]['profile_downloaded'];
            }
        }
        
        // Add local results with masking
        $localFormatted = [];
        foreach ($localResults as $local) {
            $candId = (string)$local['candidate_id'];
            $isUnmasked = isset($unmaskedMap[$candId]);
            
            $localFormatted[] = [
                'candidate_id' => $candId,
                'profile' => $local['profile'],
                'name' => $local['full_name'],
                'email' => ($isUnmasked && $unmaskedMap[$candId]['email_unmasked']) 
                    ? $local['email'] 
                    : maskEmail($local['email']),
                'email_masked' => !($isUnmasked && $unmaskedMap[$candId]['email_unmasked']),
                'mobile' => ($isUnmasked && $unmaskedMap[$candId]['mobile_unmasked']) 
                    ? $local['phone'] 
                    : maskMobile($local['phone']),
                'mobile_masked' => !($isUnmasked && $unmaskedMap[$candId]['mobile_unmasked']),
                'profile_downloaded' => $isUnmasked && $unmaskedMap[$candId]['profile_downloaded'],
                'source' => 'local_applicants',
            ];
        }
        
        $searchResults['local_results'] = $localFormatted;
        $searchResults['tokens_deducted'] = $searchCost;
        $searchResults['new_balance'] = $balance - $searchCost;
        
        // Log keywords for learning
        if (isset($searchResults['keywords']) && is_array($searchResults['keywords'])) {
            foreach ($searchResults['keywords'] as $kw) {
                try {
                    $stmt = $pdo->prepare('
                        INSERT INTO search_keywords (keyword, frequency, source)
                        VALUES (?, 1, "search")
                        ON DUPLICATE KEY UPDATE frequency = frequency + 1
                    ');
                    $stmt->execute([strtolower($kw)]);
                } catch (Exception $e) {
                    // Continue
                }
            }
        }
        
        respondJson($searchResults);
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respondJson(['error' => 'Search failed', 'details' => $e->getMessage()], 500);
    }
}

// UNMASK EMAIL
else if ($action === 'unmask-email') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondJson(['error' => 'Method not allowed'], 405);
    }
    
    $input = getJsonInput();
    $candidateId = trim($input['candidate_id'] ?? '');
    $email = trim($input['email'] ?? '');
    
    if (empty($candidateId) || empty($email)) {
        respondJson(['error' => 'Candidate ID and email are required'], 400);
    }
    
    try {
        $userId = getCurrentUserId();
        $cost = (int)$config['token_costs']['unmask_email'];
        
        // Check if already unmasked
        $stmt = $pdo->prepare('
            SELECT email_unmasked FROM unmasked_contacts 
            WHERE user_id = ? AND candidate_id = ?
        ');
        $stmt->execute([$userId, $candidateId]);
        $existing = $stmt->fetch();
        
        if ($existing && $existing['email_unmasked']) {
            respondJson(['success' => true, 'email' => $email, 'message' => 'Already unmasked']);
        }
        
        // Deduct tokens
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare('SELECT token_balance FROM employer_tokens WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        if (!$result || (int)$result['token_balance'] < $cost) {
            $pdo->rollBack();
            respondJson(['error' => 'Insufficient tokens', 'required' => $cost], 400);
        }
        
        $stmt = $pdo->prepare('
            UPDATE employer_tokens 
            SET token_balance = token_balance - ?, total_spent = total_spent + ? 
            WHERE user_id = ?
        ');
        $stmt->execute([$cost, $cost, $userId]);
        
        // Log transaction
        $stmt = $pdo->prepare('
            INSERT INTO token_transactions (user_id, transaction_type, tokens, candidate_id, description)
            VALUES (?, "unmask_email", ?, ?, ?)
        ');
        $stmt->execute([$userId, -$cost, $candidateId, 'Unmasked email for candidate']);
        
        // Record unmasked contact
        if ($existing) {
            $stmt = $pdo->prepare('
                UPDATE unmasked_contacts SET email_unmasked = 1 WHERE user_id = ? AND candidate_id = ?
            ');
            $stmt->execute([$userId, $candidateId]);
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO unmasked_contacts (user_id, candidate_id, email_unmasked)
                VALUES (?, ?, 1)
            ');
            $stmt->execute([$userId, $candidateId]);
        }
        
        $pdo->commit();
        
        respondJson([
            'success' => true,
            'email' => $email,
            'tokens_deducted' => $cost
        ]);
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respondJson(['error' => 'Failed to unmask email', 'details' => $e->getMessage()], 500);
    }
}

// UNMASK MOBILE
else if ($action === 'unmask-mobile') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondJson(['error' => 'Method not allowed'], 405);
    }
    
    $input = getJsonInput();
    $candidateId = trim($input['candidate_id'] ?? '');
    $mobile = trim($input['mobile'] ?? '');
    
    if (empty($candidateId) || empty($mobile)) {
        respondJson(['error' => 'Candidate ID and mobile are required'], 400);
    }
    
    try {
        $userId = getCurrentUserId();
        $cost = (int)$config['token_costs']['unmask_mobile'];
        
        // Check if already unmasked
        $stmt = $pdo->prepare('
            SELECT mobile_unmasked FROM unmasked_contacts 
            WHERE user_id = ? AND candidate_id = ?
        ');
        $stmt->execute([$userId, $candidateId]);
        $existing = $stmt->fetch();
        
        if ($existing && $existing['mobile_unmasked']) {
            respondJson(['success' => true, 'mobile' => $mobile, 'message' => 'Already unmasked']);
        }
        
        // Deduct tokens
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare('SELECT token_balance FROM employer_tokens WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        if (!$result || (int)$result['token_balance'] < $cost) {
            $pdo->rollBack();
            respondJson(['error' => 'Insufficient tokens', 'required' => $cost], 400);
        }
        
        $stmt = $pdo->prepare('
            UPDATE employer_tokens 
            SET token_balance = token_balance - ?, total_spent = total_spent + ? 
            WHERE user_id = ?
        ');
        $stmt->execute([$cost, $cost, $userId]);
        
        // Log transaction
        $stmt = $pdo->prepare('
            INSERT INTO token_transactions (user_id, transaction_type, tokens, candidate_id, description)
            VALUES (?, "unmask_mobile", ?, ?, ?)
        ');
        $stmt->execute([$userId, -$cost, $candidateId, 'Unmasked mobile for candidate']);
        
        // Record unmasked contact
        if ($existing) {
            $stmt = $pdo->prepare('
                UPDATE unmasked_contacts SET mobile_unmasked = 1 WHERE user_id = ? AND candidate_id = ?
            ');
            $stmt->execute([$userId, $candidateId]);
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO unmasked_contacts (user_id, candidate_id, mobile_unmasked)
                VALUES (?, ?, 1)
            ');
            $stmt->execute([$userId, $candidateId]);
        }
        
        $pdo->commit();
        
        respondJson([
            'success' => true,
            'mobile' => $mobile,
            'tokens_deducted' => $cost
        ]);
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respondJson(['error' => 'Failed to unmask mobile', 'details' => $e->getMessage()], 500);
    }
}

// DOWNLOAD FULL PROFILE
else if ($action === 'download-profile') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondJson(['error' => 'Method not allowed'], 405);
    }
    
    $input = getJsonInput();
    $candidateId = trim($input['candidate_id'] ?? '');
    $profile = trim($input['profile'] ?? '');
    
    if (empty($candidateId) || empty($profile)) {
        respondJson(['error' => 'Candidate ID and profile are required'], 400);
    }
    
    try {
        $userId = getCurrentUserId();
        $cost = (int)$config['token_costs']['download_profile'];
        
        // Check if already downloaded
        $stmt = $pdo->prepare('
            SELECT profile_downloaded FROM unmasked_contacts 
            WHERE user_id = ? AND candidate_id = ?
        ');
        $stmt->execute([$userId, $candidateId]);
        $existing = $stmt->fetch();
        
        if ($existing && $existing['profile_downloaded']) {
            respondJson(['success' => true, 'profile' => $profile, 'message' => 'Already downloaded']);
        }
        
        // Deduct tokens
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare('SELECT token_balance FROM employer_tokens WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        if (!$result || (int)$result['token_balance'] < $cost) {
            $pdo->rollBack();
            respondJson(['error' => 'Insufficient tokens', 'required' => $cost], 400);
        }
        
        $stmt = $pdo->prepare('
            UPDATE employer_tokens 
            SET token_balance = token_balance - ?, total_spent = total_spent + ? 
            WHERE user_id = ?
        ');
        $stmt->execute([$cost, $cost, $userId]);
        
        // Log transaction
        $stmt = $pdo->prepare('
            INSERT INTO token_transactions (user_id, transaction_type, tokens, candidate_id, description)
            VALUES (?, "download_profile", ?, ?, ?)
        ');
        $stmt->execute([$userId, -$cost, $candidateId, 'Downloaded full profile']);
        
        // Record download
        if ($existing) {
            $stmt = $pdo->prepare('
                UPDATE unmasked_contacts SET profile_downloaded = 1 WHERE user_id = ? AND candidate_id = ?
            ');
            $stmt->execute([$userId, $candidateId]);
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO unmasked_contacts (user_id, candidate_id, profile_downloaded)
                VALUES (?, ?, 1)
            ');
            $stmt->execute([$userId, $candidateId]);
        }
        
        $pdo->commit();
        
        respondJson([
            'success' => true,
            'profile' => $profile,
            'tokens_deducted' => $cost
        ]);
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respondJson(['error' => 'Failed to download profile', 'details' => $e->getMessage()], 500);
    }
}

else {
    respondJson(['error' => 'Invalid action'], 400);
}
