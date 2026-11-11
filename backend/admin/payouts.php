<?php
// ============================================================
// Admin — Full Payout Queue
// Filterable list of all payout requests.
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/admin_auth.php';

$admin = AdminAuth::require();
$db    = DB::connect();

$status_filter = $_GET['status'] ?? 'pending';
$valid_statuses = ['pending', 'processing', 'completed', 'failed', 'rejected', 'all'];
if (!in_array($status_filter, $valid_statuses, true)) {
    $status_filter = 'pending';
}

$where  = $status_filter !== 'all' ? "WHERE pr.status = " . $db->quote($status_filter) : '';
$stmt   = $db->query(
    "SELECT pr.id, pr.amount_php, pr.payout_method, pr.payout_number, pr.payout_name,
            pr.status, pr.tier, pr.risk_score_at_request, pr.xendit_ref,
            pr.requested_at, pr.processed_at,
            u.uuid, u.username
     FROM payout_requests pr
     JOIN users u ON u.id = pr.user_id
     {$where}
     ORDER BY pr.requested_at DESC
     LIMIT 200"
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$status_tabs = ['pending','processing','completed','failed','rejected','all'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payouts — PuzzLink Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; }
        nav { background: #1e293b; padding: 14px 24px; display: flex;
              justify-content: space-between; align-items: center; border-bottom: 1px solid #334155; }
        nav h1 { font-size: 1rem; }
        nav a { color: #94a3b8; text-decoration: none; margin-left: 20px; font-size: .88rem; }
        .container { max-width: 1200px; margin: 0 auto; padding: 24px; }
        .tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab { padding: 6px 16px; border-radius: 6px; font-size: .85rem; text-decoration: none;
               background: #1e293b; color: #94a3b8; border: 1px solid #334155; }
        .tab.active { background: #6366f1; color: #fff; border-color: #6366f1; }
        table { width: 100%; border-collapse: collapse; font-size: .83rem; }
        th { text-align: left; padding: 10px 12px; color: #64748b; border-bottom: 1px solid #334155; }
        td { padding: 10px 12px; border-bottom: 1px solid #1e2b3c; }
        tr:hover td { background: #1e293b; }
        .btn { padding: 4px 12px; border-radius: 5px; font-size: .78rem;
               text-decoration: none; color: #fff; border: none; cursor: pointer; }
        .btn-green { background: #16a34a; }
        .btn-red   { background: #dc2626; }
        .mono { font-family: monospace; font-size: .78rem; }
        .badge { padding: 2px 8px; border-radius: 4px; font-size: .75rem; color: #fff; }
    </style>
</head>
<body>
<nav>
    <h1>🧩 PuzzLink Admin › Payouts</h1>
    <div>
        <a href="/admin/dashboard.php">Dashboard</a>
        <a href="/admin/logout.php">Logout</a>
    </div>
</nav>
<div class="container">
    <div class="tabs">
        <?php foreach ($status_tabs as $tab): ?>
        <a href="?status=<?= $tab ?>"
           class="tab <?= $tab === $status_filter ? 'active' : '' ?>">
            <?= ucfirst($tab) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <table>
        <tr>
            <th>#</th><th>User</th><th>Amount</th><th>Method</th>
            <th>Number</th><th>Name</th><th>Status</th><th>Risk</th>
            <th>Xendit Ref</th><th>Requested</th><th>Actions</th>
        </tr>
        <?php if (empty($rows)): ?>
        <tr><td colspan="11" style="color:#64748b;padding:20px">No results.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $status_colors = [
                'pending'=>'#ca8a04','processing'=>'#2563eb',
                'completed'=>'#16a34a','failed'=>'#dc2626','rejected'=>'#6b7280'
            ];
            $sc = $status_colors[$r['status']] ?? '#64748b';
        ?>
        <tr>
            <td><?= $r['id'] ?></td>
            <td><a href="/admin/user_detail.php?uuid=<?= urlencode($r['uuid']) ?>"
                   style="color:#818cf8"><?= htmlspecialchars($r['username']) ?></a></td>
            <td><strong>₱<?= number_format((float)$r['amount_php'], 2) ?></strong></td>
            <td><?= strtoupper($r['payout_method']) ?></td>
            <td class="mono"><?= htmlspecialchars($r['payout_number']) ?></td>
            <td><?= htmlspecialchars($r['payout_name']) ?></td>
            <td><span class="badge" style="background:<?= $sc ?>"><?= $r['status'] ?></span></td>
            <td class="<?= (int)$r['risk_score_at_request'] >= 100 ? '' : '' ?>"
                style="color:<?= (int)$r['risk_score_at_request'] >= 100 ? '#f87171' : 'inherit' ?>">
                <?= (int)$r['risk_score_at_request'] ?>
            </td>
            <td class="mono"><?= htmlspecialchars($r['xendit_ref'] ?? '—') ?></td>
            <td style="color:#64748b;font-size:.78rem"><?= date('M j H:i', strtotime($r['requested_at'])) ?></td>
            <td>
                <?php if ($r['status'] === 'pending'): ?>
                <a href="/admin/process_payout.php?id=<?= $r['id'] ?>&action=approve"
                   class="btn btn-green" onclick="return confirm('Approve?')">Approve</a>
                <a href="/admin/process_payout.php?id=<?= $r['id'] ?>&action=reject"
                   class="btn btn-red" onclick="return confirm('Reject?')">Reject</a>
                <?php else: ?>
                —
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
</body>
</html>
