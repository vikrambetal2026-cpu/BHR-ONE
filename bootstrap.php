<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    http_response_code(500);
    echo json_encode([
        "error" => "Unhandled exception",
        "details" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine(),
    ]);
    exit;
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if (!$err) return;

    $fatalTypes = [
        E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE,
        E_USER_ERROR, E_RECOVERABLE_ERROR
    ];
    if (!in_array($err["type"], $fatalTypes, true)) return;

    http_response_code(500);
    echo json_encode([
        "error" => "Fatal error",
        "details" => $err["message"],
        "file" => $err["file"],
        "line" => $err["line"],
        "type" => $err["type"],
    ]);
});
