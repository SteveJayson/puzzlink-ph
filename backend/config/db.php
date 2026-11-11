<?php
// ============================================================
// PuzzLink PH - Database Connection
// Uses PDO with prepared statements throughout.
// ============================================================

declare(strict_types=1);

class DB {
    private static ?PDO $instance = null;

    public static function connect(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'puzzlink_ph';
        $user = getenv('DB_USER') ?: 'puzzlink_user';
        $pass = getenv('DB_PASS') ?: '';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        try {
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Never expose DB error details to the client
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(503);
            echo json_encode(['error' => 'Service temporarily unavailable']);
            exit;
        }

        return self::$instance;
    }
}
