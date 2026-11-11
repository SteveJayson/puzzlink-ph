<?php
// ============================================================
// Admin — User Detail Page
// Shows full transaction history, risk flags, payout history,
// and allows banning/unbanning the account.
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/admin_auth.php';

$admin = AdminAuth::require();
$db    = DB::connect();

$uuid = trim($_GET['uuid'] ?? '');
if ($uuid === '') {
    header('Location: /admin/users.php');
    exit;
}

// --- Handle ban/unban action ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    AdminAuth::requireRole('admin');
    $post_action = $_POST['action'] ?? '';
    $post_reason = trim($_POST['reason'] ?? '');
    $uid_check   = $_POST['uuid'] ?? '';

    if ($uid_check === $uuid) {
        if ($post_action === 'ban') {
            $db->prepare(
                "UPDATE users SET is_banned = 1, ban_reason = ? WHERE uuid = ?"
            )->execute([$post_reason ?: 'Banned by admin', $uuid]);
        } elseif ($post_action === 'unban') {
            $db->prepare(
                "UPDATE users SET is_banned = 0, ban_reason = NULL WHERE uuid = ?"
            )->execute([$uuid]);
            // Reset risk score on unban
            $db->prepare(
                "UPDATE user_risk_scores SET risk_score = 0 WHERE user_id =
                 (SELECT id FROM users WHERE uuid = ?)"
            )->execute([$uuid]);
        }
    }
    header("Location: /admin/user_detail.php?uuid={$uuid}&msg=done");
    exit;
}

// --- Fetch user ---
$stmt = $db->prepare(
    'SELECT u.*, COALESCE(urs.risk_score, 0) AS risk_score,
            b.coins, b.php_equivalent, b.total_earned, b.total_paid_out
     FROM users u
     LEFT JOIN user_risk_scores urs ON urs.user_id = u.id
     LEFT JOIN balances b ON b.user_id = u.id
     WHERE u.uuid = ? LIMIT 1'
);
$stmt->execute([$uuid]);
$user = $stmt->fetch();

if (!$user) {
    echo '<p>User not found</p>';
    exit;
}

$user_id = (int)$user['id'];

// --- Recent transactions (last 30) ---
$transactions = $db->prepare(
    'SELECT source_type, amount_coins, php_value, created_at, ip_address
     FROM transactions WHERE user_id = ?
     ORDER BY created_at DESC LIMIT 30'
);
$transactions->execute([$user_id]);
$txns = $transactions->fetchAll();

// --- Payout history ---
$payouts_stmt = $db->prepare(
    'SELECT id, amount_php, payout_method, payout_number, status, tier, requested_at, processed_at
     FROM payout_requests WHERE user_id = ? ORDER BY requested_at DESC LIMIT 20'
);
$payouts_stmt->execute([$user_id]);
$payouts = $payouts_stmt->fetchAll();

// --- Risk flags ---
$flags_stmt = $db->prepare(
    'SELECT flag_type, severity, details, reviewed, created_at
     FROM risk_flags WHERE user_id = ? ORDER BY created_at DESC LIMIT 20'
);
$flags_stmt->execute([$user_id]);
$flags = $flags_stmt->fetchAll();

// --- Device fingerprints ---
$fp_stmt = $db->prepare(
    'SELECT device_model, os_version, android_id, ip_address, is_flagged, created_at
     FROM device_fingerprints WHERE user_id = ? ORDER BY created_at DESC LIMIT 10'
);
$fp_stmt->execute([$user_id]);
$fingerprints = $fp_stmt->fetchAll();

function status_color(string $s): string {
    return match($s) {
        'completed' => '#16a34a', 'pending' => '#ca8a04',
        'failed','rejected' => '#dc2626', default => '#64748b'
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User: <?= htmlspecialchars($user['username']) ?> — PuzzLink Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; }
        nav { background: #1e293b; padding: 14px 24px; display: flex;
              justify-content: space-between; align-items: center; border-bottom: 1px solid #334155; }
        nav h1 { font-size: 1rem; }
        nav a { color: #94a3b8; text-decoration: none; margin-left: 20px; font-size: .88rem; }
        .container { max-width: 1100px; margin: 0 auto; padding: 24px; }
        .profile { background: #1e293b; border-radius: 12px; padding: 24px;
                   display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 28px; }
        .profile .field label { font-size: .75rem; color: #64748b; text-transform: uppercase; }
        .profile .field p { font-size: 1rem; color: #f1f5f9; margin-top: 4px; }
        .profile .field p.big { font-size: 1.4rem; font-weight: 700; }
        h2 { font-size: 1rem; color: #f1f5f9; margin-bottom: 12px;
             padding-bottom: 8px; border-bottom: 1px solid #334155; }
        section { margin-bottom: 32px; }
        table { width: 100%; border-collapse: collapse; font-size: .83rem; }
        th { text-align: left; padding: 8px 12px; color: #64748b; border-bottom: 1px solid #334155; }
        td { padding: 8px 12px; border-bottom: 1px solid #1e2b3c; }
        tr:hover td { background: #1e293b; }
        .mono { font-family: monospace; font-size: .8rem; }
        .badge { padding: 2px 8px; border-radius: 4px; font-size: .75rem; color: #fff; }
        .banned-banner { background: #450a0a; border: 1px solid #dc2626; border-radius: 8px;
                         padding: 14px 20px; margin-bottom: 20px; color: #fca5a5; }
        .actions { background: #1e293b; border-radius: 10px; padding: 20px; margin-top: 28px; }
        .actions h2 { margin-bottom: 16px; }
        .actions form { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
        input[type=text] { background: #0f172a; border: 1px solid #334155; color: #f1f5f9;
                           padding: 8px 12px; border-radius: 6px; font-size: .9rem; }
        .btn { padding: 8px 18px; border-radius: 6px; border: none; cursor: pointer;
               font-size: .88rem; color: #fff; }
        .btn-red { background: #dc2626; }
        .btn-green { background: #16a34a; }
    </style>
</head>
<body>
<nav>
    <h1>🧩 PuzzLink Admin › User Detail</h1>
    <div>
        <a href="/admin/dashboard.php">Dashboard</a>
        <a href="/admin/users.php">← Users</a>
        <a href="/admin/logout.php">Logout</a>
    </div>
</nav>

<div class="container">
    <?php if ((int)$user['is_banned']): ?>
    <div class="banned-banner">
        🚫 This account is <strong>BANNED</strong>. Reason: <?= htmlspecialchars($user['ban_reason'] ?? 'No reason given') ?>
    </div>
    <?php endif; ?>

    <!-- Profile -->
    <div class="profile">
        <div class="field"><label>Username</label><p><?= htmlspecialchars($user['username']) ?></p></div>
        <div class="field"><label>UUID</label><p class="mono" style="font-size:.75rem"><?= htmlspecialchars($user['uuid']) ?></p></div>
        <div class="field"><label>Joined</label><p><?= date('M j, Y', strtotime($user['created_at'])) ?></p></div>
        <div class="field"><label>Balance</label><p class="big">₱<?= number_format((float)$user['php_equivalent'], 2) ?></p></div>
        <div class="field"><label>Total Earned</label><p>₱<?= number_format((float)$user['total_earned'], 2) ?></p></div>
        <div class="field"><label>Total Paid Out</label><p>₱<?= number_format((float)$user['total_paid_out'], 2) ?></p></div>
        <div class="field">
            <label>Risk Score</label>
            <p class="big" style="color:<?= (int)$user['risk_score'] >= 100 ? '#f87171' : '#34d399' ?>">
                <?= (int)$user['risk_score'] ?>
            </p>
        </div>
        <div class="field"><label>Android ID</label><p class="mono" style="font-size:.75rem"><?= htmlspecialchars($user['android_id'] ?? '—') ?></p></div>
        <div class="field"><label>Last Login</label><p><?= $user['last_login_at'] ? date('M j H:i', strtotime($user['last_login_at'])) : '—' ?></p></div>
    </div>

    <!-- Risk Flags -->
    <section>
        <h2>🚩 Risk Flags (<?= count($flags) ?>)</h2>
        <?php if (empty($flags)): ?><p style="color:#64748b">No flags.</p><?php else: ?>
        <table>
            <tr><th>Type</th><th>Severity</th><th>Details</th><th>Reviewed</th><th>Date</th></tr>
            <?php foreach ($flags as $f): ?>
            <tr>
                <td class="mono"><?= htmlspecialchars($f['flag_type']) ?></td>
                <td><?= htmlspecialchars($f['severity']) ?></td>
                <td style="font-size:.78rem;color:#94a3b8;max-width:300px">
                    <?= htmlspecialchars(substr((string)$f['details'], 0, 150)) ?>
                </td>
                <td><?= $f['reviewed'] ? '✓' : '—' ?></td>
                <td style="color:#64748b;font-size:.78rem"><?= date('M j H:i', strtotime($f['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </section>

    <!-- Transactions -->
    <section>
        <h2>📒 Recent Transactions (last 30)</h2>
        <table>
            <tr><th>Type</th><th>Coins</th><th>₱ Value</th><th>IP</th><th>Date</th></tr>
            <?php foreach ($txns as $t): ?>
            <tr>
                <td class="mono"><?= htmlspecialchars($t['source_type']) ?></td>
                <td style="color:<?= (int)$t['amount_coins'] > 0 ? '#34d399' : '#f87171' ?>">
                    <?= (int)$t['amount_coins'] > 0 ? '+' : '' ?><?= (int)$t['amount_coins'] ?>
                </td>
                <td>₱<?= number_format(abs((float)$t['php_value']), 4) ?></td>
                <td class="mono" style="font-size:.78rem"><?= htmlspecialchars($t['ip_address'] ?? '—') ?></td>
                <td style="color:#64748b;font-size:.78rem"><?= date('M j H:i', strtotime($t['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </section>

    <!-- Payouts -->
    <section>
        <h2>💸 Payout History</h2>
        <?php if (empty($payouts)): ?><p style="color:#64748b">No payouts yet.</p><?php else: ?>
        <table>
            <tr><th>#</th><th>Amount</th><th>Method</th><th>Number</th><th>Status</th><th>Requested</th><th>Processed</th></tr>
            <?php foreach ($payouts as $p): ?>
            <tr>
                <td><?= $p['id'] ?></td>
                <td>₱<?= number_format((float)$p['amount_php'], 2) ?></td>
                <td><?= strtoupper($p['payout_method']) ?></td>
                <td class="mono"><?= htmlspecialchars($p['payout_number']) ?></td>
                <td><span class="badge" style="background:<?= status_color($p['status']) ?>"><?= $p['status'] ?></span></td>
                <td style="color:#64748b;font-size:.78rem"><?= date('M j H:i', strtotime($p['requested_at'])) ?></td>
                <td style="color:#64748b;font-size:.78rem"><?= $p['processed_at'] ? date('M j H:i', strtotime($p['processed_at'])) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </section>

    <!-- Device Fingerprints -->
    <section>
        <h2>📱 Device Fingerprints</h2>
        <?php if (empty($fingerprints)): ?><p style="color:#64748b">No fingerprints recorded.</p><?php else: ?>
        <table>
            <tr><th>Model</th><th>OS</th><th>Android ID</th><th>IP</th><th>Flagged</th><th>First Seen</th></tr>
            <?php foreach ($fingerprints as $fp): ?>
            <tr>
                <td><?= htmlspecialchars($fp['device_model'] ?? '—') ?></td>
                <td><?= htmlspecialchars($fp['os_version'] ?? '—') ?></td>
                <td class="mono" style="font-size:.78rem"><?= htmlspecialchars($fp['android_id'] ?? '—') ?></td>
                <td class="mono" style="font-size:.78rem"><?= htmlspecialchars($fp['ip_address'] ?? '—') ?></td>
                <td><?= $fp['is_flagged'] ? '🚩' : '—' ?></td>
                <td style="color:#64748b;font-size:.78rem"><?= date('M j H:i', strtotime($fp['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </section>

    <!-- Ban / Unban Actions -->
    <?php if ($admin['role'] === 'admin' || $admin['role'] === 'super_admin'): ?>
    <div class="actions">
        <h2>⚠️ Account Actions</h2>
        <?php if (!(int)$user['is_banned']): ?>
        <form method="POST">
            <input type="hidden" name="uuid" value="<?= htmlspecialchars($uuid) ?>">
            <input type="hidden" name="action" value="ban">
            <input type="text" name="reason" placeholder="Ban reason (required)" required style="width:300px">
            <button type="submit" class="btn btn-red"
                    onclick="return confirm('Ban this user? Their pending payouts will NOT be refunded automatically.')">
                Ban User
            </button>
        </form>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="uuid" value="<?= htmlspecialchars($uuid) ?>">
            <input type="hidden" name="action" value="unban">
            <button type="submit" class="btn btn-green"
                    onclick="return confirm('Unban this user and reset their risk score?')">
                Unban User
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
