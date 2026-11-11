<?php
// ============================================================
// Admin Dashboard — Overview
// Shows pending payouts, flagged users, and daily stats.
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/admin_auth.php';

$admin = AdminAuth::require();
$db    = DB::connect();

// --- Summary stats ---
$stats = [];

$stats['pending_payouts'] = (int)$db->query(
    "SELECT COUNT(*) FROM payout_requests WHERE status = 'pending'"
)->fetchColumn();

$stats['flagged_users'] = (int)$db->query(
    "SELECT COUNT(DISTINCT user_id) FROM risk_flags WHERE reviewed = 0"
)->fetchColumn();

$stats['total_users'] = (int)$db->query(
    "SELECT COUNT(*) FROM users WHERE is_banned = 0"
)->fetchColumn();

$stats['today_payouts_php'] = (float)$db->query(
    "SELECT COALESCE(SUM(amount_php),0) FROM payout_requests
     WHERE status = 'completed' AND DATE(processed_at) = CURDATE()"
)->fetchColumn();

$stats['today_earned_php'] = (float)$db->query(
    "SELECT COALESCE(SUM(php_value),0) FROM transactions
     WHERE amount_coins > 0 AND DATE(created_at) = CURDATE()"
)->fetchColumn();

// --- Recent pending payouts ---
$pending = $db->query(
    "SELECT pr.id, pr.amount_php, pr.payout_method, pr.payout_number, pr.payout_name,
            pr.requested_at, pr.tier, pr.risk_score_at_request,
            u.uuid, u.username
     FROM payout_requests pr
     JOIN users u ON u.id = pr.user_id
     WHERE pr.status = 'pending'
     ORDER BY pr.requested_at ASC
     LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);

// --- Recent risk flags (unreviewed) ---
$flags = $db->query(
    "SELECT rf.id, rf.flag_type, rf.severity, rf.created_at, rf.details,
            u.username, u.uuid, urs.risk_score
     FROM risk_flags rf
     JOIN users u ON u.id = rf.user_id
     LEFT JOIN user_risk_scores urs ON urs.user_id = rf.user_id
     WHERE rf.reviewed = 0
     ORDER BY
         FIELD(rf.severity,'critical','high','medium','low'),
         rf.created_at DESC
     LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);

function severity_badge(string $s): string {
    $colors = ['critical'=>'#dc2626','high'=>'#ea580c','medium'=>'#ca8a04','low'=>'#16a34a'];
    $c = $colors[$s] ?? '#6b7280';
    return "<span style='background:{$c};color:#fff;padding:2px 8px;border-radius:4px;font-size:.75rem'>" . htmlspecialchars($s) . "</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PuzzLink Admin — Dashboard</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; }
        nav { background: #1e293b; padding: 14px 24px; display: flex;
              justify-content: space-between; align-items: center; border-bottom: 1px solid #334155; }
        nav h1 { font-size: 1rem; color: #f1f5f9; }
        nav .links a { color: #94a3b8; text-decoration: none; margin-left: 20px; font-size: .88rem; }
        nav .links a:hover { color: #f1f5f9; }
        .container { max-width: 1200px; margin: 0 auto; padding: 24px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 32px; }
        .stat-card { background: #1e293b; border-radius: 10px; padding: 20px; border: 1px solid #334155; }
        .stat-card .val { font-size: 1.8rem; font-weight: 700; color: #f1f5f9; }
        .stat-card .lbl { font-size: .78rem; color: #64748b; margin-top: 4px; text-transform: uppercase; letter-spacing: .05em; }
        .stat-card.alert .val { color: #f87171; }
        h2 { color: #f1f5f9; font-size: 1.05rem; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 1px solid #334155; }
        section { margin-bottom: 36px; }
        table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        th { text-align: left; padding: 10px 12px; color: #64748b; font-weight: 500;
             border-bottom: 1px solid #334155; white-space: nowrap; }
        td { padding: 10px 12px; border-bottom: 1px solid #1e293b; vertical-align: middle; }
        tr:hover td { background: #1e293b; }
        .btn { display: inline-block; padding: 5px 14px; border-radius: 6px; font-size: .8rem;
               text-decoration: none; cursor: pointer; border: none; color: #fff; }
        .btn-green  { background: #16a34a; }
        .btn-red    { background: #dc2626; }
        .btn-yellow { background: #ca8a04; color: #000; }
        .btn-blue   { background: #2563eb; }
        .btn:hover { opacity: .85; }
        .risk-high { color: #f87171; }
        .mono { font-family: monospace; font-size: .8rem; }
    </style>
</head>
<body>
<nav>
    <h1>🧩 PuzzLink Admin</h1>
    <div class="links">
        <a href="/admin/dashboard.php">Dashboard</a>
        <a href="/admin/payouts.php">Payouts</a>
        <a href="/admin/risk_flags.php">Risk Flags</a>
        <a href="/admin/users.php">Users</a>
        <a href="/admin/logout.php">Logout</a>
    </div>
</nav>

<div class="container">

    <!-- Stats -->
    <div class="stats">
        <div class="stat-card <?= $stats['pending_payouts'] > 0 ? 'alert' : '' ?>">
            <div class="val"><?= $stats['pending_payouts'] ?></div>
            <div class="lbl">Pending Payouts</div>
        </div>
        <div class="stat-card <?= $stats['flagged_users'] > 0 ? 'alert' : '' ?>">
            <div class="val"><?= $stats['flagged_users'] ?></div>
            <div class="lbl">Flagged Users</div>
        </div>
        <div class="stat-card">
            <div class="val"><?= number_format($stats['total_users']) ?></div>
            <div class="lbl">Active Users</div>
        </div>
        <div class="stat-card">
            <div class="val">₱<?= number_format($stats['today_earned_php'], 2) ?></div>
            <div class="lbl">Earned Today</div>
        </div>
        <div class="stat-card">
            <div class="val">₱<?= number_format($stats['today_payouts_php'], 2) ?></div>
            <div class="lbl">Paid Out Today</div>
        </div>
        <div class="stat-card">
            <div class="val">₱<?= number_format($stats['today_earned_php'] - $stats['today_payouts_php'], 2) ?></div>
            <div class="lbl">Today's Net</div>
        </div>
    </div>

    <!-- Pending Payouts -->
    <section>
        <h2>⏳ Pending Payout Requests (<?= count($pending) ?>)</h2>
        <?php if (empty($pending)): ?>
            <p style="color:#64748b;padding:16px 0">No pending payouts.</p>
        <?php else: ?>
        <table>
            <tr>
                <th>#</th><th>User</th><th>Amount</th><th>Method</th>
                <th>Number</th><th>Name</th><th>Tier</th><th>Risk</th>
                <th>Requested</th><th>Actions</th>
            </tr>
            <?php foreach ($pending as $p): ?>
            <tr>
                <td class="mono"><?= $p['id'] ?></td>
                <td><a href="/admin/user_detail.php?uuid=<?= urlencode($p['uuid']) ?>"
                       style="color:#818cf8"><?= htmlspecialchars($p['username']) ?></a></td>
                <td><strong>₱<?= number_format((float)$p['amount_php'], 2) ?></strong></td>
                <td><?= strtoupper(htmlspecialchars($p['payout_method'])) ?></td>
                <td class="mono"><?= htmlspecialchars($p['payout_number']) ?></td>
                <td><?= htmlspecialchars($p['payout_name']) ?></td>
                <td>Tier <?= $p['tier'] ?></td>
                <td class="<?= $p['risk_score_at_request'] >= 100 ? 'risk-high' : '' ?>">
                    <?= $p['risk_score_at_request'] ?>
                </td>
                <td style="color:#64748b;font-size:.78rem"><?= date('M j H:i', strtotime($p['requested_at'])) ?></td>
                <td>
                    <a href="/admin/process_payout.php?id=<?= $p['id'] ?>&action=approve"
                       class="btn btn-green" onclick="return confirm('Approve this payout?')">Approve</a>
                    <a href="/admin/process_payout.php?id=<?= $p['id'] ?>&action=reject"
                       class="btn btn-red" onclick="return confirm('Reject this payout?')">Reject</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </section>

    <!-- Risk Flags -->
    <section>
        <h2>🚩 Unreviewed Risk Flags (<?= count($flags) ?>)</h2>
        <?php if (empty($flags)): ?>
            <p style="color:#64748b;padding:16px 0">No unreviewed flags.</p>
        <?php else: ?>
        <table>
            <tr>
                <th>User</th><th>Flag Type</th><th>Severity</th>
                <th>Risk Score</th><th>Details</th><th>Flagged At</th><th>Action</th>
            </tr>
            <?php foreach ($flags as $f):
                $details = json_decode($f['details'], true) ?? [];
            ?>
            <tr>
                <td><a href="/admin/user_detail.php?uuid=<?= urlencode($f['uuid']) ?>"
                       style="color:#818cf8"><?= htmlspecialchars($f['username']) ?></a></td>
                <td class="mono"><?= htmlspecialchars($f['flag_type']) ?></td>
                <td><?= severity_badge($f['severity']) ?></td>
                <td class="<?= (int)$f['risk_score'] >= 100 ? 'risk-high' : '' ?>">
                    <?= (int)$f['risk_score'] ?>
                </td>
                <td style="font-size:.78rem;color:#94a3b8;max-width:220px;overflow:hidden;white-space:nowrap">
                    <?= htmlspecialchars(json_encode($details)) ?>
                </td>
                <td style="color:#64748b;font-size:.78rem"><?= date('M j H:i', strtotime($f['created_at'])) ?></td>
                <td>
                    <a href="/admin/review_flag.php?id=<?= $f['id'] ?>&action=dismiss"
                       class="btn btn-yellow">Dismiss</a>
                    <a href="/admin/user_detail.php?uuid=<?= urlencode($f['uuid']) ?>"
                       class="btn btn-blue">View User</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </section>

</div>
</body>
</html>
