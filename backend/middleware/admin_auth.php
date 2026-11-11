<?php
// ============================================================
// admin_auth.php — Admin Dashboard Auth Middleware
// Session-based (not JWT) since admins use a browser.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

class AdminAuth {

    public static function require(): array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['admin_id'])) {
            header('Location: /admin/login.php');
            exit;
        }

        $db   = DB::connect();
        $stmt = $db->prepare(
            'SELECT id, username, role FROM admin_users
             WHERE id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch();

        if (!$admin) {
            session_destroy();
            header('Location: /admin/login.php');
            exit;
        }

        return $admin;
    }

    public static function requireRole(string $role): array {
        $admin         = self::require();
        $role_hierarchy = ['reviewer' => 1, 'admin' => 2, 'super_admin' => 3];
        $required_level = $role_hierarchy[$role]       ?? 1;
        $admin_level    = $role_hierarchy[$admin['role']] ?? 0;

        if ($admin_level < $required_level) {
            http_response_code(403);
            echo '<h1>403 — Insufficient permissions</h1>';
            exit;
        }

        return $admin;
    }

    public static function login(string $username, string $password): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $db   = DB::connect();
        $stmt = $db->prepare(
            'SELECT id, password_hash, role FROM admin_users
             WHERE username = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            return false;
        }

        $_SESSION['admin_id']   = $admin['id'];
        $_SESSION['admin_role'] = $admin['role'];

        $db->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?')
           ->execute([$admin['id']]);

        return true;
    }

    public static function logout(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();
        header('Location: /admin/login.php');
        exit;
    }
}
