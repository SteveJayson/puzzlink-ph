<?php
// ============================================================
// PuzzLink PH - JWT Auth Middleware
// Validates Bearer token on every protected endpoint.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';

class Auth {

    /**
     * Require a valid JWT. Returns the decoded payload array,
     * or exits 401 if the token is missing/invalid/expired.
     */
    public static function require(): array {
        $token = self::extractToken();

        if ($token === null) {
            self::fail('Missing or malformed Authorization header');
        }

        $payload = self::decodeJWT($token);

        if ($payload === null) {
            self::fail('Invalid or expired token');
        }

        // Confirm user still exists and is not banned
        $db = DB::connect();
        $stmt = $db->prepare('SELECT id, is_banned FROM users WHERE uuid = ? LIMIT 1');
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch();

        if (!$user) {
            self::fail('User not found');
        }

        if ((int)$user['is_banned'] === 1) {
            http_response_code(403);
            echo json_encode(['error' => 'Account suspended']);
            exit;
        }

        // Attach DB id to payload for downstream use
        $payload['user_id'] = (int)$user['id'];
        return $payload;
    }

    /**
     * Issue a signed JWT for a given user UUID.
     */
    public static function issueToken(string $uuid): string {
        $header  = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::b64url(json_encode([
            'sub' => $uuid,
            'iat' => time(),
            'exp' => time() + JWT_TTL_SEC,
        ]));

        $sig = self::b64url(hash_hmac('sha256', "{$header}.{$payload}", JWT_SECRET, true));
        return "{$header}.{$payload}.{$sig}";
    }

    // ---- private helpers ----

    private static function extractToken(): ?string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    private static function decodeJWT(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $sig] = $parts;

        $expected = self::b64url(hash_hmac('sha256', "{$header}.{$payload}", JWT_SECRET, true));
        if (!hash_equals($expected, $sig)) return null;

        $data = json_decode(self::b64urlDecode($payload), true);
        if (!$data || $data['exp'] < time()) return null;

        return $data;
    }

    private static function b64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }

    private static function fail(string $message): never {
        http_response_code(401);
        echo json_encode(['error' => $message]);
        exit;
    }
}
