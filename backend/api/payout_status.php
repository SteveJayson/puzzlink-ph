<?php
// ============================================================
// GET /api/payout/status
// Protected. Returns payout history for the authenticated user.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/response.php';

header('Content-Type: application/json');
require_method('GET');

$auth    = Auth::require();
$user_id = $auth['user_id'];

$db   = DB::connect();
$stmt = $db->prepare(
    'SELECT id, amount_php, payout_method, status, tier, requested_at, processed_at
     FROM payout_requests
     WHERE user_id = ?
     ORDER BY requested_at DESC
     LIMIT 20'
);
$stmt->execute([$user_id]);
$payouts = $stmt->fetchAll();

json_ok(['payouts' => $payouts]);
