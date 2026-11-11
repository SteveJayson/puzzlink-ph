<?php
// ============================================================
// Admin — Process a Payout (Approve / Reject)
// Handles both manual approval and auto-disbursement via Xendit.
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../middleware/admin_auth.php';
require_once __DIR__ . '/../services/XenditService.php';

$admin = AdminAuth::requireRole('admin');

$payout_id = (int)($_GET['id']     ?? 0);
$action    = strtolower(trim($_GET['action'] ?? ''));
$notes     = trim($_POST['notes'] ?? '');

if ($payout_id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    header('Location: /admin/dashboard.php');
    exit;
}

$db = DB::connect();
$db->beginTransaction();

try {
    $stmt = $db->prepare(
        'SELECT pr.*, u.uuid FROM payout_requests pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.id = ? AND pr.status = \'pending\' FOR UPDATE'
    );
    $stmt->execute([$payout_id]);
    $payout = $stmt->fetch();

    if (!$payout) {
        $db->rollBack();
        header('Location: /admin/dashboard.php?error=not_found');
        exit;
    }

    if ($action === 'reject') {
        // --- REJECT: refund coins ---
        $user_id      = (int)$payout['user_id'];
        $amount_php   = (float)$payout['amount_php'];
        $coins_refund = (int)ceil($amount_php * COINS_PER_PHP);
        $idem_key     = hash('sha256', "refund:payout_{$payout_id}:admin");

        $db->prepare(
            "UPDATE payout_requests
             SET status = 'rejected', processed_at = NOW(),
                 reviewed_by = ?, review_notes = ?, rejection_reason = ?
             WHERE id = ?"
        )->execute([$admin['id'], $notes ?: null, $notes ?: 'Rejected by admin', $payout_id]);

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

        $db->commit();
        header('Location: /admin/dashboard.php?msg=rejected');
        exit;
    }

    // --- APPROVE: attempt Xendit disbursement ---
    $xendit_enabled = (getenv('XENDIT_SECRET_KEY') !== false && getenv('XENDIT_SECRET_KEY') !== '');

    if ($xendit_enabled) {
        try {
            $xendit = new XenditService();
            $result = $xendit->disburse(
                $payout_id,
                $payout['payout_method'],
                $payout['payout_number'],
                (float)$payout['amount_php'],
                $payout['payout_name']
            );

            if ($result['success']) {
                $db->prepare(
                    "UPDATE payout_requests
                     SET status = 'processing', processed_at = NOW(),
                         reviewed_by = ?, xendit_ref = ?, review_notes = ?
                     WHERE id = ?"
                )->execute([$admin['id'], $result['xendit_id'], $notes ?: null, $payout_id]);
                $db->commit();
                header('Location: /admin/dashboard.php?msg=disbursement_sent');
                exit;
            } else {
                // Xendit failed — mark as failed, refund
                $db->rollBack();
                // Re-open a new transaction for the failure path
                $db->beginTransaction();
                $user_id      = (int)$payout['user_id'];
                $amount_php   = (float)$payout['amount_php'];
                $coins_refund = (int)ceil($amount_php * COINS_PER_PHP);

                $db->prepare(
                    "UPDATE payout_requests
                     SET status = 'failed', processed_at = NOW(),
                         reviewed_by = ?, rejection_reason = ?
                     WHERE id = ?"
                )->execute([$admin['id'], 'Xendit: ' . $result['message'], $payout_id]);

                $idem_key = hash('sha256', "refund:xendit_fail:{$payout_id}");
                $db->prepare(
                    'INSERT IGNORE INTO transactions
                        (user_id, amount_coins, php_value, source_type, source_ref, idempotency_key)
                     VALUES (?, ?, ?, \'admin_adjustment\', ?, ?)'
                )->execute([$user_id, $coins_refund, $amount_php, "refund_payout_{$payout_id}", $idem_key]);

                $db->prepare(
                    'UPDATE balances SET coins = coins + ?, php_equivalent = php_equivalent + ?,
                     total_paid_out = total_paid_out - ? WHERE user_id = ?'
                )->execute([$coins_refund, $amount_php, $amount_php, $user_id]);

                $db->commit();
                header('Location: /admin/dashboard.php?error=xendit_failed&msg=' . urlencode($result['message']));
                exit;
            }
        } catch (RuntimeException $e) {
            // Xendit not configured — fall through to manual approval
        }
    }

    // --- Manual approval (no Xendit configured) ---
    // Admin will disburse manually via GCash/Maya app, then mark complete.
    $db->prepare(
        "UPDATE payout_requests
         SET status = 'processing', processed_at = NOW(),
             reviewed_by = ?, review_notes = ?
         WHERE id = ?"
    )->execute([$admin['id'], $notes ?: 'Manually approved', $payout_id]);

    $db->commit();
    header('Location: /admin/payouts.php?msg=approved_manual');

} catch (Throwable $e) {
    $db->rollBack();
    error_log('process_payout admin error: ' . $e->getMessage());
    header('Location: /admin/dashboard.php?error=internal');
}
