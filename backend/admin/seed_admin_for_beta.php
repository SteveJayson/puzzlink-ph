<?php
// ============================================================
// seed_admin_for_beta.php
// CLI helper: adds test coins to beta testers' accounts so
// they can reach the ₱50 threshold and test the payout flow.
// 
// Usage: php backend/admin/seed_admin_for_beta.php <uuid> <php_amount>
// Example: php backend/admin/seed_admin_for_beta.php abc-123 50
// ============================================================
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

$uuid       = $argv[1] ?? '';
$php_amount = (float)($argv[2] ?? 0);

if ($uuid === '' || $php_amount <= 0) {
    echo "Usage: php seed_admin_for_beta.php <uuid> <php_amount>\n";
    exit(1);
}

$db = DB::connect();

$stmt = $db->prepare('SELECT id, username FROM users WHERE uuid = ? LIMIT 1');
$stmt->execute([$uuid]);
$user = $stmt->fetch();

if (!$user) {
    echo "Error: user not found\n";
    exit(1);
}

$user_id    = (int)$user['id'];
$coins      = (int)round($php_amount * COINS_PER_PHP);
$idem_key   = hash('sha256', "beta_seed:{$user_id}:" . time());

$db->beginTransaction();
try {
    $db->prepare(
        'INSERT INTO transactions
            (user_id, amount_coins, php_value, source_type, source_ref, idempotency_key)
         VALUES (?, ?, ?, \'admin_adjustment\', \'beta_seed\', ?)'
    )->execute([$user_id, $coins, $php_amount, $idem_key]);

    $db->prepare(
        'UPDATE balances
         SET coins = coins + ?, php_equivalent = php_equivalent + ?, total_earned = total_earned + ?
         WHERE user_id = ?'
    )->execute([$coins, $php_amount, $php_amount, $user_id]);

    $db->commit();
    echo "✓ Added ₱{$php_amount} ({$coins} coins) to {$user['username']} (UUID: {$uuid})\n";
} catch (Throwable $e) {
    $db->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
