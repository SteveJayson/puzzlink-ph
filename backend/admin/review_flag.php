<?php
// ============================================================
// Admin — Dismiss or Escalate a Risk Flag
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/admin_auth.php';

$admin     = AdminAuth::requireRole('reviewer');
$flag_id   = (int)($_GET['id']     ?? 0);
$action    = strtolower(trim($_GET['action'] ?? ''));

if ($flag_id <= 0 || !in_array($action, ['dismiss', 'escalate'], true)) {
    header('Location: /admin/dashboard.php');
    exit;
}

$db = DB::connect();

if ($action === 'dismiss') {
    $db->prepare(
        'UPDATE risk_flags SET reviewed = 1, reviewer_id = ?, reviewed_at = NOW()
         WHERE id = ?'
    )->execute([$admin['id'], $flag_id]);
} elseif ($action === 'escalate') {
    // Escalate: add 50 points to risk score and mark reviewed
    $stmt = $db->prepare('SELECT user_id FROM risk_flags WHERE id = ?');
    $stmt->execute([$flag_id]);
    $flag = $stmt->fetch();
    if ($flag) {
        $db->prepare(
            'UPDATE risk_flags SET reviewed = 1, reviewer_id = ?, reviewed_at = NOW() WHERE id = ?'
        )->execute([$admin['id'], $flag_id]);
        $db->prepare(
            'INSERT INTO user_risk_scores (user_id, risk_score) VALUES (?, 50)
             ON DUPLICATE KEY UPDATE risk_score = risk_score + 50, last_flag_at = NOW()'
        )->execute([$flag['user_id']]);
    }
}

header('Location: /admin/dashboard.php');
