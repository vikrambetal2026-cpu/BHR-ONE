<?php
declare(strict_types=1);

/**
 * Normal (Non-AI) Candidate Search
 * Uses basic FULLTEXT search without AI optimization
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/db.php';

$config = require __DIR__ . '/config.php';

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function readJsonBody(): array {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw ?: "{}", true);
    return is_array($data) ? $data : [];
}

$input = readJsonBody();
$userQuery = trim((string)($input['query'] ?? ''));

if ($userQuery === '') {
    respond(["error" => "Empty query"], 400);
}

// Extract simple keywords from query
function extractSimpleKeywords(string $query): array {
    $query = strtolower($query);
    $words = preg_split('/\s+/', $query);
    
    $stopWords = ['with', 'in', 'and', 'for', 'year', 'years', 'yrs', 'experience', 
                  'exp', 'of', 'a', 'an', 'the', 'looking', 'need', 'want', 'hiring'];
    
    $keywords = [];
    foreach ($words as $word) {
        $word = preg_replace('/[^a-z0-9\+]+/i', '', $word);
        if ($word !== '' && strlen($word) >= 2 && !in_array($word, $stopWords)) {
            $keywords[] = $word;
        }
    }
    
    return array_slice(array_unique($keywords), 0, 10);
}

$keywords = extractSimpleKeywords($userQuery);
$rows = [];

try {
    // Method 1: FULLTEXT Natural Language search
    $sqlNL = "
        SELECT
            cf.candidate_id,
            SUBSTRING(cf.free_text, 1, 3500) AS profile,
            MATCH(cf.free_text) AGAINST (:q IN NATURAL LANGUAGE MODE) AS relevance
        FROM candidate_freetext cf
        WHERE MATCH(cf.free_text) AGAINST (:q IN NATURAL LANGUAGE MODE)
        ORDER BY relevance DESC
        LIMIT 50
    ";
    
    $stmt = $pdo->prepare($sqlNL);
    $stmt->execute(["q" => $userQuery]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no results, try LIKE-based search
    if (empty($rows) && !empty($keywords)) {
        $conditions = [];
        $params = [];
        
        foreach ($keywords as $i => $kw) {
            $conditions[] = "cf.free_text LIKE :kw{$i}";
            $params["kw{$i}"] = '%' . $kw . '%';
        }
        
        $whereClause = implode(' OR ', $conditions);
        
        $sqlLike = "
            SELECT
                cf.candidate_id,
                SUBSTRING(cf.free_text, 1, 3500) AS profile
            FROM candidate_freetext cf
            WHERE {$whereClause}
            LIMIT 50
        ";
        
        $stmt = $pdo->prepare($sqlLike);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (Throwable $e) {
    respond([
        "error" => "Search failed",
        "details" => $e->getMessage()
    ], 500);
}

// Simple keyword matching for relevance
foreach ($rows as &$row) {
    $profile = strtolower($row['profile'] ?? '');
    $matchedKeywords = [];
    
    foreach ($keywords as $kw) {
        if (strpos($profile, strtolower($kw)) !== false) {
            $matchedKeywords[] = $kw;
        }
    }
    
    $row['matched_keywords'] = $matchedKeywords;
    $row['match_score'] = count($matchedKeywords);
}
unset($row);

// Sort by match score
usort($rows, function($a, $b) {
    return ($b['match_score'] ?? 0) - ($a['match_score'] ?? 0);
});

respond([
    "original_query" => $userQuery,
    "keywords" => $keywords,
    "search_type" => "normal",
    "results" => array_slice($rows, 0, 20),
    "total_found" => count($rows),
]);
