<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$config = require __DIR__ . '/config.php';
require __DIR__ . '/internal_url.php';
if (($_GET['key'] ?? '') !== ($config['api_key'] ?? '')) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized. Add ?key=CHANGE_ME_API_KEY"]);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
    http_response_code(400);
    echo json_encode(["error" => "Missing q"]);
    exit;
}

// dummy candidate (minimal) - replace with your real candidate_id if needed
$candidates = [
  ["candidate_id" => "demo1", "profile" => "NAME: Demo\nKEY SKILLS: Doctor Radiology MD 3 years Chennai"]
];

$ch = curl_init(internal_api_url("hf_b.php"));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "X-API-KEY: " . $config['api_key'],
    ],
    CURLOPT_POSTFIELDS => json_encode([
        "query" => $q,
        "candidates" => $candidates,
        "keywords" => [],
    ]),
]);

$resp = curl_exec($ch);
$err = curl_error($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err || !$resp) {
    http_response_code(500);
    echo json_encode(["error" => "Internal call failed", "details" => $err ?: "empty", "http_code" => $http]);
    exit;
}

http_response_code($http);
header("Content-Type: application/json; charset=utf-8");
echo $resp;
