<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/internal_url.php';

$config = require __DIR__ . '/config.php';
$API_KEY = (string)($config['api_key'] ?? '');
$CURL_INSECURE_INTERNAL = (bool)($config['curl_insecure_internal'] ?? false);

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

function postJson(string $url, array $payload, string $apiKey, bool $insecure, int $timeout = 60): array {
    $ch = curl_init($url);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-API-KEY: {$apiKey}",
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ];

    if ($insecure) {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }

    curl_setopt_array($ch, $opts);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || !$resp) {
        return ["error" => "curl_failed", "details" => $err ?: "empty", "http_code" => $http];
    }

    $json = json_decode($resp, true);
    if (!is_array($json)) {
        return ["error" => "invalid_json", "details" => substr($resp, 0, 400), "http_code" => $http];
    }

    $json["_http_code"] = $http;
    return $json;
}

function warn_from(string $prefix, array $resp): string {
    $msg = isset($resp["error"]) ? (string)$resp["error"] : "unknown_error";
    $det = isset($resp["details"]) ? (string)$resp["details"] : "";
    $http = isset($resp["_http_code"]) ? (string)$resp["_http_code"] : (isset($resp["http_code"]) ? (string)$resp["http_code"] : "");
    $extra = [];
    if ($http !== "") $extra[] = "http={$http}";
    if ($det !== "") $extra[] = "details=" . mb_substr($det, 0, 220);
    return $prefix . ": " . $msg . (count($extra) ? (" | " . implode(" | ", $extra)) : "");
}

// ---------- INPUT ----------
$input = readJsonBody();
$userQuery = trim((string)($input['query'] ?? ''));
if ($userQuery === '') respond(["error" => "Empty query"], 400);

$warnings = [];

// ---------- STEP 1: A ----------
$optimizedQuery = $userQuery;
$keywords = [];

$aiAResponse = postJson(
    internal_api_url('hf_a.php'),
    ["query" => $userQuery],
    $API_KEY,
    $CURL_INSECURE_INTERNAL,
    60
);

if (!empty($aiAResponse["optimized_query"])) $optimizedQuery = trim((string)$aiAResponse["optimized_query"]);
if (!empty($aiAResponse["keywords"]) && is_array($aiAResponse["keywords"])) {
    $keywords = array_values(array_filter(array_map('strval', $aiAResponse["keywords"])));
}
if (!empty($aiAResponse["error"])) $warnings[] = warn_from("A", $aiAResponse);

// fallback keywords if A fails
if (!$keywords) {
    $tokens = preg_split('/\s+/', strtolower($userQuery)) ?: [];
    $stop = array_fill_keys(["with","in","and","for","year","years","yrs","experience","exp","of","a","an","the"], true);
    $kw = [];
    foreach ($tokens as $t) {
        $t = preg_replace('/[^a-z0-9\+]+/i', '', $t) ?? $t;
        if ($t === "" || isset($stop[$t])) continue;
        $kw[$t] = true;
        if (count($kw) >= 5) break;
    }
    $keywords = array_keys($kw);
}

// ---------- STEP 2: DB SEARCH ----------
function sanitize_boolean_term(string $t): string {
    $t = strtolower(trim($t));
    $t = preg_replace('/[^a-z0-9_]+/i', '', $t) ?? $t;
    return $t;
}

function buildBooleanQuery(array $keywords, string $userQuery): array {
    $qLower = strtolower($userQuery);

    $specialties = ["radiology","cardiology","orthopedic","icu","anesthesia","pathology","dermatology"];
    $degrees = ["md","mbbs","dnb","dm","ms","mch","bds","dgo"];
    $roles = ["doctor","radiologist","physician","technician","nurse","sonographer"];

    $must = [];
    $optional = [];

    // detect location from "in/at/from/near <word>"
    $location = null;
    if (preg_match('/\b(?:in|at|from|near)\s+([a-z]{3,})\b/i', $userQuery, $mm)) {
        $location = sanitize_boolean_term($mm[1]);
        if (in_array($location, array_merge($specialties, $degrees, $roles, ["fresher","trainee"]), true)) {
            $location = null;
        }
    }

    foreach ($keywords as $kw) {
        $kw = sanitize_boolean_term((string)$kw);
        if ($kw === "") continue;

        if (in_array($kw, $specialties, true) || in_array($kw, $degrees, true) || in_array($kw, $roles, true)) {
            $must[$kw] = true;
        } else {
            // keep optional simple tokens only (avoid "3 years")
            if (strlen($kw) >= 3) $optional[$kw] = true;
        }
    }

    if ($location) $must[$location] = true;

    $mustList = array_keys($must);
    $optList  = array_keys($optional);

    $bool = [];
    foreach ($mustList as $t) $bool[] = '+' . $t;
    foreach ($optList as $t) $bool[] = $t;

    return [
        "location" => $location,
        "must" => $mustList,
        "optional" => $optList,
        "boolean" => trim(implode(' ', $bool)),
    ];
}

$rows = [];
$boolInfo = buildBooleanQuery($keywords, $userQuery);

try {
    $sqlBool = "
        SELECT
            cf.candidate_id,
            SUBSTRING(cf.free_text, 1, 3500) AS profile,
            MATCH(cf.free_text) AGAINST (:q IN BOOLEAN MODE) AS relevance
        FROM candidate_freetext cf
        WHERE MATCH(cf.free_text) AGAINST (:q IN BOOLEAN MODE)
        ORDER BY relevance DESC
        LIMIT 80
    ";

    // 1) Strict: MUST terms + location (if present)
    if ($boolInfo["boolean"] !== "") {
        $stmt = $pdo->prepare($sqlBool);
        $stmt->execute(["q" => $boolInfo["boolean"]]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 2) Relax location if too few
    if (count($rows) < 5 && !empty($boolInfo["location"]) && !empty($boolInfo["must"])) {
        $mustNoLoc = [];
        foreach ($boolInfo["must"] as $t) {
            if ($t !== $boolInfo["location"]) $mustNoLoc[] = $t;
        }

        $q2parts = [];
        foreach ($mustNoLoc as $t) $q2parts[] = '+' . $t;
        $q2 = trim(implode(' ', $q2parts));

        if ($q2 !== "") {
            $stmt = $pdo->prepare($sqlBool);
            $stmt->execute(["q" => $q2]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // 3) Fallback: natural language on optimized query
    if (empty($rows)) {
        $sqlNL = "
            SELECT
                cf.candidate_id,
                SUBSTRING(cf.free_text, 1, 3500) AS profile,
                MATCH(cf.free_text) AGAINST (:q IN NATURAL LANGUAGE MODE) AS relevance
            FROM candidate_freetext cf
            WHERE MATCH(cf.free_text) AGAINST (:q IN NATURAL LANGUAGE MODE)
            ORDER BY relevance DESC
            LIMIT 80
        ";
        $stmt = $pdo->prepare($sqlNL);
        $stmt->execute(["q" => $optimizedQuery]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $warnings[] = "DB(search): " . $e->getMessage();
    $rows = [];
}

if (empty($rows)) {
    respond([
        "original_query"  => $userQuery,
        "optimized_query" => $optimizedQuery,
        "keywords"        => $keywords,
        "results"         => [],
        "analysis"        => "No matching candidates found.",
        "warnings"        => $warnings,
    ]);
}
// ---------- PREP FOR B ----------
$topForB = [];
foreach (array_slice($rows, 0, 5) as $r) {
    $topForB[] = [
        "candidate_id" => (string)$r["candidate_id"],
        "profile" => (string)$r["profile"],
    ];
}

// ---------- STEP 3: B ----------
$analysis = "Top candidates were selected based on keyword relevance to the search criteria.";
$rankMap = [];

$aiBResponse = postJson(
    internal_api_url('hf_b.php'),
    ["query" => $userQuery, "candidates" => $topForB, "keywords" => $keywords],
    $API_KEY,
    $CURL_INSECURE_INTERNAL,
    120
);

if (!empty($aiBResponse["ranked_candidates"]) && is_array($aiBResponse["ranked_candidates"])) {
    foreach ($aiBResponse["ranked_candidates"] as $rc) {
        if (!is_array($rc)) continue;
        $cid = (string)($rc["candidate_id"] ?? "");
        if ($cid !== "") $rankMap[$cid] = $rc;
    }
}
if (!empty($aiBResponse["analysis"])) $analysis = (string)$aiBResponse["analysis"];
if (!empty($aiBResponse["error"])) $warnings[] = warn_from("B", $aiBResponse);

// Merge rating
foreach ($rows as &$r) {
    $cid = (string)$r["candidate_id"];
    if (isset($rankMap[$cid])) {
        $r["rating"] = (string)$rankMap[$cid]["rating"];
        $r["semantic_score"] = (string)$rankMap[$cid]["semantic_score"];
        $r["matched_keywords"] = $rankMap[$cid]["matched_keywords"];
    }
}
unset($r);

// If B available, sort by rating
if ($rankMap) {
    usort($rows, function ($a, $b) {
        $ra = isset($a["rating"]) ? (float)$a["rating"] : -1.0;
        $rb = isset($b["rating"]) ? (float)$b["rating"] : -1.0;
        if ($ra === $rb) return 0;
        return ($ra < $rb) ? 1 : -1;
    });
}

respond([
    "original_query"  => $userQuery,
    "optimized_query" => $optimizedQuery,
    "keywords"        => $keywords,
    "analysis"        => $analysis,
    "results"         => array_slice($rows, 0, 10),
    "warnings"        => $warnings,
]);
