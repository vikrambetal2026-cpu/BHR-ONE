<?php
header("Content-Type: application/json; charset=utf-8");

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($debug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

function json_out($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT);
    exit;
}

$dir = __DIR__;
$files = array(
    "config.php",
    "auth.php",
    "db.php",
    "bootstrap.php",
    "session.php",
    "user_auth.php",
    "jobs.php",
    "applications.php",
    "tokens.php",
    "search_with_tokens.php",
    "ai_candidate_search.php",
    "razorpay_webhook.php",
    "ping.php"
);

$status = array();
foreach ($files as $f) {
    $path = $dir . DIRECTORY_SEPARATOR . $f;
    $status[$f] = array(
        "exists" => file_exists($path),
        "readable" => is_readable($path),
        "size" => file_exists($path) ? filesize($path) : null,
    );
}

$base = array(
    "php" => PHP_VERSION,
    "curl_loaded" => extension_loaded("curl"),
    "pdo_loaded" => extension_loaded("pdo"),
    "pdo_mysql_loaded" => extension_loaded("pdo_mysql"),
    "dir" => $dir,
    "files" => $status,
);

try {
    $configPath = __DIR__ . '/config.php';
    $config = require $configPath;

    $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset={$config['db']['charset']}";
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));

    $tables = array();
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    sort($tables);

    $required = array(
        "users",
        "employer_tokens",
        "token_transactions",
        "jobs",
        "job_applications"
    );

    $missing = array();
    foreach ($required as $t) {
        if (!in_array($t, $tables, true)) {
            $missing[] = $t;
        }
    }

    $usersCols = array();
    $usersMissingCols = array();
    if (in_array("users", $tables, true)) {
        $colsStmt = $pdo->query("DESCRIBE users");
        while ($r = $colsStmt->fetch()) {
            $usersCols[] = $r['Field'];
        }

        $expected = array("email", "password", "full_name", "role");
        foreach ($expected as $c) {
            if (!in_array($c, $usersCols, true)) {
                $usersMissingCols[] = $c;
            }
        }
    }

    $base["db"] = array(
        "ok" => true,
        "database" => $config['db']['name'],
        "tables" => $tables,
        "missing_required_tables" => $missing,
        "users_columns" => $usersCols,
        "users_missing_expected_columns" => $usersMissingCols
    );
} catch (Throwable $e) {
    $base["db"] = array(
        "ok" => false,
        "error" => "DB check failed",
        "details" => $e->getMessage(),
    );
    if ($debug) {
        $base["db"]["trace"] = $e->getTraceAsString();
    }
}

json_out($base, 200);
