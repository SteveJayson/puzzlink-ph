<?php
// ============================================================
// Admin Dashboard — Login Page
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/admin_auth.php';

session_start();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (AdminAuth::login($username, $password)) {
        header('Location: /admin/dashboard.php');
        exit;
    }
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PuzzLink Admin — Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #0f172a;
               display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #1e293b; border-radius: 12px; padding: 40px;
                width: 100%; max-width: 380px; box-shadow: 0 20px 60px rgba(0,0,0,.4); }
        h1 { color: #f1f5f9; font-size: 1.4rem; margin-bottom: 8px; }
        p  { color: #94a3b8; font-size: .85rem; margin-bottom: 28px; }
        label { display: block; color: #cbd5e1; font-size: .85rem; margin-bottom: 6px; }
        input { width: 100%; padding: 10px 14px; background: #0f172a; border: 1px solid #334155;
                border-radius: 8px; color: #f1f5f9; font-size: .95rem; margin-bottom: 16px; }
        input:focus { outline: none; border-color: #6366f1; }
        button { width: 100%; padding: 11px; background: #6366f1; color: #fff;
                 border: none; border-radius: 8px; font-size: .95rem; cursor: pointer; }
        button:hover { background: #4f46e5; }
        .error { color: #f87171; background: #450a0a; border-radius: 6px;
                 padding: 10px 14px; font-size: .85rem; margin-bottom: 16px; }
    </style>
</head>
<body>
<div class="card">
    <h1>🔐 PuzzLink Admin</h1>
    <p>Staff access only. Unauthorized access is logged.</p>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST" action="/admin/login.php">
        <label>Username</label>
        <input type="text" name="username" autocomplete="off" required>
        <label>Password</label>
        <input type="password" name="password" required>
        <button type="submit">Sign In</button>
    </form>
</div>
</body>
</html>
