<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");

$config = require __DIR__ . '/config.php';
$EXPECTED_KEY = (string)($config['api_key'] ?? '');

$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!$headers) {
    foreach ($_SERVER as $name => $value) {
        if (strpos($name, 'HTTP_') === 0) {
            $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$key] = $value;
        }
    }
}

$apiKey = $headers['X-API-KEY'] ?? $headers['X-Api-Key'] ?? $headers['x-api-key'] ?? '';

if ($EXPECTED_KEY === '' || $apiKey !== $EXPECTED_KEY) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}
