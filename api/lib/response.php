<?php
declare(strict_types=1);

function json_response(bool $ok, mixed $data = null, ?array $error = null, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'ok' => $ok,
        'data' => $data,
        'error' => $error,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function success(mixed $data = null, int $status = 200): void
{
    json_response(true, $data, null, $status);
}

function error_response(string $code, string $message, int $status = 400, array $fields = []): void
{
    json_response(false, null, [
        'code' => $code,
        'message' => $message,
        'fields' => $fields,
    ], $status);
}
