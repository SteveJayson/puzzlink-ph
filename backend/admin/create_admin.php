<?php
// ============================================================
// create_admin.php — Run once via CLI to create first admin
// Usage: php backend/admin/create_admin.php
// ============================================================
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';

$db = DB::connect();

echo "PuzzLink Admin User Setup\n";
echo "=========================\n";

$username = readline('Username: ');
$email    = readline('Email: ');
$password = readline('Password (min 12 chars): ');
$role     = readline('Role [super_admin/admin/reviewer] (default: admin): ') ?: 'admin';

if (strlen(trim($username)) < 3) {
    exit("Error: username too short\n");
}
if (strlen($password) < 12) {
    exit("Error: password must be at least 12 characters\n");
}
if (!in_array($role, ['super_admin', 'admin', 'reviewer'], true)) {
    exit("Error: invalid role\n");
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

try {
    $db->prepare(
        'INSERT INTO admin_users (username, password_hash, email, role)
         VALUES (?, ?, ?, ?)'
    )->execute([trim($username), $hash, trim($email), $role]);
    echo "\n✓ Admin user '{$username}' created with role '{$role}'.\n";
    echo "  Login at https://YOUR_SERVER/admin/login.php\n";
} catch (PDOException $e) {
    exit("Error: " . $e->getMessage() . "\n");
}
