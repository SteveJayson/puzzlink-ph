<?php
// ============================================================
// PuzzLink PH - JSON Response Helper
// ============================================================

declare(strict_types=1);

function json_ok(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function json_error(string $message, int $code = 400, array $extra = []): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    exit;
}

function require_method(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
        json_error('Method not allowed', 405);
    }
}

function get_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('Invalid JSON body', 400);
    }
    return $data;
}
