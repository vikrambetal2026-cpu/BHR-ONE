<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$config = require __DIR__ . '/config.php';
$CURL_INSECURE_EXTERNAL = (bool)($config['curl_insecure_external'] ?? true);
require __DIR__ . '/hf_tools.php';

$hfToken = (string)($config['hf_token'] ?? '');

$input = json_decode(file_get_contents("php://input") ?: "{}", true);
$input = is_array($input) ? $input : [];

$query = trim((string)($input["query"] ?? ""));
$candidates = $input["candidates"] ?? [];
$keywords = $input["keywords"] ?? [];

if (!is_array($candidates) || empty($candidates)) {
    echo json_encode(["analysis" => "No candidates to analyze.", "ranked_candidates" => []]);
    exit;
}
if (!is_array($keywords)) $keywords = [];
if ($hfToken === '') {
    http_response_code(500);
    echo json_encode(["error" => "Missing hf_token in config.php"]);
    exit;
}

// Limit candidates for speed
$candidates = array_slice($candidates, 0, 5);

try {
    if (!$keywords) $keywords = extract_keywords_A($query, $hfToken);

    $debug = [];
    $ranked = rank_candidates_B($query, $candidates, $keywords, $hfToken, $debug);
    if (!$ranked) {
        http_response_code(502);
        echo json_encode(["error" => "B_failed", "details" => "Ranking returned empty output", "debug" => $debug]);
        exit;
    }

    $lines = [];
    $lines[] = "Top matches for: {$query}";
    foreach (array_slice($ranked, 0, 5) as $i => $r) {
        $name = $r["name"] !== "" ? $r["name"] : ("Candidate " . ($i + 1));
        $mk = $r["matched_keywords"] ? implode(", ", $r["matched_keywords"]) : "none";
        $lines[] = ($i + 1) . ") {$name} (ID {$r["candidate_id"]}) — Rating {$r["rating"]}/100; matched: {$mk}";
    }

    echo json_encode([
        "analysis" => implode("\n", $lines),
        "ranked_candidates" => $ranked,
        "keywords_used" => $keywords,
        "debug" => $debug,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "B_failed",
        "details" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine(),
    ]);
}
