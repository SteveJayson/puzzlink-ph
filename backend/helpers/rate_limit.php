<?php
// ============================================================
// PuzzLink PH - Rate Limiter
// Token-bucket style: counts hits in a rolling window.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

function rate_limit_check(string $identifier, string $endpoint): void {
    $db = DB::connect();

    $window_start = date('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW_SEC);

    // Count recent hits
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS hits FROM rate_limit_log
         WHERE identifier = ? AND endpoint = ? AND hit_at >= ?'
    );
    $stmt->execute([$identifier, $endpoint, $window_start]);
    $hits = (int)$stmt->fetch()['hits'];

    if ($hits >= RATE_LIMIT_MAX_HITS) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Too many requests. Please slow down.']);
        exit;
    }

    // Log this hit
    $db->prepare('INSERT INTO rate_limit_log (identifier, endpoint) VALUES (?, ?)')
       ->execute([$identifier, $endpoint]);

    // Prune old records periodically (1-in-50 chance to avoid per-request overhead)
    if (random_int(1, 50) === 1) {
        $cutoff = date('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW_SEC * 10);
        $db->prepare('DELETE FROM rate_limit_log WHERE hit_at < ?')->execute([$cutoff]);
    }
}
