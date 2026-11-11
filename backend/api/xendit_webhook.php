<?php
// ============================================================
// POST /api/xendit/webhook
// Xendit calls this when a disbursement status changes.
// Validates the callback token, then updates payout status.
//
// Configure in Xendit dashboard:
//   Webhooks → Disbursement → URL: https://YOUR_SERVER/api/xendit/webhook
//   Token: set XENDIT_WEBHOOK_TOKEN in .env
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../services/XenditService.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// --- Validate Xendit callback token ---
$token   = $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '';
$xendit  = new XenditService();
if (!$xendit->validateWebhookToken($token)) {
    error_log('Xendit webhook: invalid callback token from IP ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Xendit disbursement webhook fields
$xendit_id     = (string)($data['id']          ?? '');
$external_id   = (string)($data['external_id'] ?? '');  // "puzzlink_payout_{id}"
$xendit_status = strtolower((string)($data['status'] ?? ''));
$failure_code  = (string)($data['failure_code'] ?? '');

// Extract our payout_id from external_id
$payout_id = 0;
if (preg_match('/puzzlink_payout_(\d+)$/', $external_id, $m)) {
    $payout_id = (int)$m[1];
}

if ($payout_id === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Unrecognised external_id']);
    exit;
}

// Map Xendit status → our status
$status_map = [
    'completed' => 'completed',
    'failed'    => 'failed',
    'pending'   => 'processing',
];
$new_status = $status_map[$xendit_status] ?? null;

if ($new_status === null) {
    // Unknown status — log and return 200 to stop retries
    error_log("Xendit webhook unknown status: {$xendit_status} for payout {$payout_id}");
    echo json_encode(['received' => true]);
    exit;
}

$db = DB::connect();
$db->beginTransaction();
try {
    // Fetch payout to get user_id and current status
    $stmt = $db->prepare(
        'SELECT user_id, status, amount_php FROM payout_requests WHERE id = ? FOR UPDATE'
    );
    $stmt->execute([$payout_id]);
    $payout = $stmt->fetch();

    if (!$payout) {
        $db->rollBack();
        error_log("Xendit webhook: payout {$payout_id} not found");
        echo json_encode(['received' => true]);
        exit;
    }

    // Idempotent: skip if already in final state
    if (in_array($payout['status'], ['completed', 'failed'], true)) {
        $db->rollBack();
        echo json_encode(['received' => true]);
        exit;
    }

    $db->prepare(
        'UPDATE payout_requests
         SET status       = ?,
             xendit_ref   = ?,
             processed_at = NOW(),
             rejection_reason = ?
         WHERE id = ?'
    )->execute([
        $new_status,
        $xendit_id,
        $new_status === 'failed' ? "Xendit: {$failure_code}" : null,
        $payout_id,
    ]);

    // On failure: refund coins back to user
    if ($new_status === 'failed') {
        $amount_php    = (float)$payout['amount_php'];
        $coins_refund  = (int)ceil($amount_php * COINS_PER_PHP);
        $user_id       = (int)$payout['user_id'];
        $idem_key      = hash('sha256', "refund:payout_{$payout_id}");

        $db->prepare(
            'INSERT IGNORE INTO transactions
                (user_id, amount_coins, php_value, source_type, source_ref, idempotency_key)
             VALUES (?, ?, ?, \'admin_adjustment\', ?, ?)'
        )->execute([$user_id, $coins_refund, $amount_php, "refund_payout_{$payout_id}", $idem_key]);

        $db->prepare(
            'UPDATE balances
             SET coins = coins + ?, php_equivalent = php_equivalent + ?,
                 total_paid_out = total_paid_out - ?
             WHERE user_id = ?'
        )->execute([$coins_refund, $amount_php, $amount_php, $user_id]);
    }

    $db->commit();
    echo json_encode(['received' => true]);

} catch (Throwable $e) {
    $db->rollBack();
    error_log('xendit_webhook failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}
