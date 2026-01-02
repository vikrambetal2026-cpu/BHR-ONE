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

$userQuery = trim((string)($input['query'] ?? ''));
if ($userQuery === '') {
    http_response_code(400);
    echo json_encode(["error" => "Empty query"]);
    exit;
}
if ($hfToken === '') {
    http_response_code(500);
    echo json_encode(["error" => "Missing hf_token in config.php"]);
    exit;
}

try {
    $keywords = extract_keywords_A($userQuery, $hfToken);
    $optimized = implode(' ', $keywords);
    echo json_encode([
        "optimized_query" => $optimized !== '' ? $optimized : $userQuery,
        "keywords" => $keywords,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "A_failed",
        "details" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine(),
    ]);
}
