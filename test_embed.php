<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$config = require __DIR__ . '/config.php';
if (($_GET['key'] ?? '') !== ($config['api_key'] ?? '')) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized. Add ?key=CHANGE_ME_API_KEY"]);
    exit;
}

require __DIR__ . '/hf_tools.php';

$hfToken = (string)($config['hf_token'] ?? '');
if ($hfToken === '') {
    http_response_code(500);
    echo json_encode(["error" => "Missing hf_token in config.php"]);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') $q = "Doctor with 3 years MD Radiology";

$sentences = [
    "Doctor Radiology MD 3 years Chennai",
    "MBA Finance 12 years Accounts",
    "ICU duty doctor 5 years",
];

$res = hf_sentence_similarity_scores($q, $sentences, $hfToken);
if (!($res["ok"] ?? false)) {
    http_response_code(502);
    echo json_encode(["error" => "similarity_failed", "res" => $res]);
    exit;
}

echo json_encode([
    "ok" => true,
    "model" => $res["model"],
    "scores" => $res["scores"],
    "note" => "Higher score means closer semantic match to the query.",
]);
