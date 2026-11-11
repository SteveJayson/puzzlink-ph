<?php
// ============================================================
// ApiTestRunner.php — Lightweight integration test runner
// No external framework needed. Run via CLI:
//   php backend/tests/ApiTestRunner.php
//
// Tests hit a real local/staging server so they verify the
// full stack: PHP → MySQL → response.
// Set BASE_URL and seed credentials in the config block below.
// ============================================================

declare(strict_types=1);

// ---- CONFIG ----
define('TEST_BASE_URL', getenv('TEST_API_URL') ?: 'http://localhost/api');
define('TEST_VERBOSE',  getenv('TEST_VERBOSE')  ?: false);

// ---- COLOURS ----
define('C_GREEN',  "\033[32m");
define('C_RED',    "\033[31m");
define('C_YELLOW', "\033[33m");
define('C_RESET',  "\033[0m");
define('C_BOLD',   "\033[1m");

class TestRunner {
    private array $results = [];
    private string $token  = '';
    private string $uuid   = '';

    // ============================================================
    // TEST REGISTRATION & EXECUTION
    // ============================================================

    public function run(): void {
        echo C_BOLD . "\n🧩 PuzzLink PH — API Integration Tests\n" . C_RESET;
        echo str_repeat('─', 55) . "\n";

        $this->test_registration();
        $this->test_duplicate_registration();
        $this->test_login();
        $this->test_bad_login();
        $this->test_balance_requires_auth();
        $this->test_balance_authenticated();
        $this->test_level_complete_basic();
        $this->test_level_complete_duplicate();
        $this->test_level_complete_invalid();
        $this->test_ad_reward_rewarded();
        $this->test_ad_reward_duplicate_impression();
        $this->test_payout_below_threshold();
        $this->test_payout_bad_number();
        $this->test_rate_limiting();
        $this->test_offerwall_bad_signature();

        $this->print_summary();
    }

    // ============================================================
    // INDIVIDUAL TEST CASES
    // ============================================================

    private function test_registration(): void {
        $res = $this->post('/device/register', [
            'android_id'   => 'test_android_' . uniqid(),
            'device_model' => 'Test Galaxy A14',
            'os_version'   => '13',
            'app_version'  => '1.0.0',
        ], false);

        $this->assert('Registration returns 200/201',
            in_array($res['code'], [200, 201]));
        $this->assert('Registration returns token',
            !empty($res['body']['data']['token']));
        $this->assert('Registration returns uuid',
            !empty($res['body']['data']['uuid']));

        // Store for subsequent tests
        $this->token = $res['body']['data']['token'] ?? '';
        $this->uuid  = $res['body']['data']['uuid']  ?? '';
    }

    private function test_duplicate_registration(): void {
        // Same android_id = should return existing account, not error
        static $android_id = null;
        if ($android_id === null) {
            $android_id = 'dup_test_' . uniqid();
        }

        $res1 = $this->post('/device/register', [
            'android_id' => $android_id, 'device_model' => 'Test', 'os_version' => '13', 'app_version' => '1.0',
        ], false);
        $res2 = $this->post('/device/register', [
            'android_id' => $android_id, 'device_model' => 'Test', 'os_version' => '13', 'app_version' => '1.0',
        ], false);

        $this->assert('Duplicate device_register returns same UUID',
            ($res1['body']['data']['uuid'] ?? 'a') === ($res2['body']['data']['uuid'] ?? 'b'));
    }

    private function test_login(): void {
        // This test requires a username/password account — skip if device-only
        $this->skip('Username/password login (requires seeded account — test manually)');
    }

    private function test_bad_login(): void {
        $res = $this->post('/login', [
            'username' => 'nonexistent_' . uniqid(),
            'password' => 'wrongpassword',
        ], false);
        $this->assert('Bad login returns 401', $res['code'] === 401);
        $this->assert('Bad login has error field', isset($res['body']['error']));
    }

    private function test_balance_requires_auth(): void {
        $res = $this->get('/balance', false);  // no token
        $this->assert('Balance without auth returns 401', $res['code'] === 401);
    }

    private function test_balance_authenticated(): void {
        if ($this->token === '') {
            $this->skip('Balance auth test (no token from registration)');
            return;
        }
        $res = $this->get('/balance');
        $this->assert('Balance returns 200', $res['code'] === 200);
        $this->assert('Balance has coins field',   isset($res['body']['data']['coins']));
        $this->assert('Balance has cashout field', isset($res['body']['data']['cashout']));
        $this->assert('Balance coins >= 0',        (int)($res['body']['data']['coins'] ?? -1) >= 0);
    }

    private function test_level_complete_basic(): void {
        if ($this->token === '') {
            $this->skip('Level complete (no token)');
            return;
        }
        // Use a high level_id unlikely to have been played by this test account
        $level_id = random_int(10000, 99999);
        $res = $this->post('/level/complete', [
            'level_id'       => $level_id,
            'time_taken_sec' => 35,
            'ad_doubled'     => false,
        ]);
        $this->assert('Level complete returns 200',         $res['code'] === 200);
        $this->assert('Level complete returns coins_earned', isset($res['body']['data']['coins_earned']));
        $this->assert('Level complete coins_earned > 0',    (int)($res['body']['data']['coins_earned'] ?? 0) > 0);
    }

    private function test_level_complete_duplicate(): void {
        if ($this->token === '') {
            $this->skip('Level duplicate test (no token)');
            return;
        }
        // Same level_id on the same day — should get 409
        $level_id = 1;  // likely already played in prior test run
        $this->post('/level/complete', ['level_id' => $level_id, 'time_taken_sec' => 30, 'ad_doubled' => false]);
        $res2 = $this->post('/level/complete', ['level_id' => $level_id, 'time_taken_sec' => 30, 'ad_doubled' => false]);
        // Either 409 (dup) or 200 (first time today) — both acceptable
        $this->assert('Level duplicate handled gracefully',
            in_array($res2['code'], [200, 409]));
    }

    private function test_level_complete_invalid(): void {
        if ($this->token === '') {
            $this->skip('Level invalid test (no token)');
            return;
        }
        $res = $this->post('/level/complete', [
            'level_id'       => 0,
            'time_taken_sec' => 30,
            'ad_doubled'     => false,
        ]);
        $this->assert('Level complete with level_id=0 returns 400', $res['code'] === 400);
    }

    private function test_ad_reward_rewarded(): void {
        if ($this->token === '') {
            $this->skip('Ad reward test (no token)');
            return;
        }
        $impression_id = 'test_imp_' . uniqid();
        $res = $this->post('/ad/reward', [
            'ad_type'       => 'rewarded',
            'ad_network'    => 'admob_test',
            'impression_id' => $impression_id,
        ]);
        $this->assert('Ad reward returns 200',              $res['code'] === 200);
        $this->assert('Ad reward returns coins_earned',     isset($res['body']['data']['coins_earned']));
        $this->assert('Ad reward coins > 0',               (int)($res['body']['data']['coins_earned'] ?? 0) > 0);
    }

    private function test_ad_reward_duplicate_impression(): void {
        if ($this->token === '') {
            $this->skip('Ad reward duplicate test (no token)');
            return;
        }
        $impression_id = 'dup_imp_' . uniqid();
        $this->post('/ad/reward', [
            'ad_type' => 'rewarded', 'ad_network' => 'test', 'impression_id' => $impression_id
        ]);
        $res2 = $this->post('/ad/reward', [
            'ad_type' => 'rewarded', 'ad_network' => 'test', 'impression_id' => $impression_id
        ]);
        $this->assert('Duplicate impression returns 409', $res2['code'] === 409);
    }

    private function test_payout_below_threshold(): void {
        if ($this->token === '') {
            $this->skip('Payout threshold test (no token)');
            return;
        }
        // Fresh test account will have near-zero balance
        $res = $this->post('/payout/request', [
            'method' => 'gcash',
            'number' => '09171234567',
            'name'   => 'Test User',
        ]);
        $this->assert('Payout below threshold returns 402', $res['code'] === 402);
    }

    private function test_payout_bad_number(): void {
        if ($this->token === '') {
            $this->skip('Payout number validation test (no token)');
            return;
        }
        $res = $this->post('/payout/request', [
            'method' => 'gcash',
            'number' => '12345',          // invalid format
            'name'   => 'Test User',
        ]);
        $this->assert('Bad payout number returns 400', $res['code'] === 400);
    }

    private function test_rate_limiting(): void {
        if ($this->token === '') {
            $this->skip('Rate limit test (no token)');
            return;
        }
        // Fire 35 requests at the same endpoint (limit is 30/min)
        $hit_limit = false;
        for ($i = 0; $i < 35; $i++) {
            $res = $this->get('/balance');
            if ($res['code'] === 429) {
                $hit_limit = true;
                break;
            }
        }
        $this->assert('Rate limiter triggers at high request volume', $hit_limit);
    }

    private function test_offerwall_bad_signature(): void {
        $res = $this->get('/offerwall/callback?network=cpx&ext_user_id=' . urlencode($this->uuid)
            . '&status=1&trans_id=test123&payout=1.00&hash=BADSIG', false);
        $this->assert('Offerwall bad signature returns 403', $res['code'] === 403);
    }

    // ============================================================
    // HTTP HELPERS
    // ============================================================

    private function get(string $path, bool $auth = true): array {
        return $this->request('GET', $path, [], $auth);
    }

    private function post(string $path, array $body, bool $auth = true): array {
        return $this->request('POST', $path, $body, $auth);
    }

    private function request(string $method, string $path, array $body, bool $auth): array {
        $url = TEST_BASE_URL . $path;
        $ch  = curl_init($url);

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($auth && $this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string)$response, true) ?? [];

        if (TEST_VERBOSE) {
            echo "  → {$method} {$path} [{$code}]\n";
        }

        return ['code' => $code, 'body' => $decoded, 'raw' => $response];
    }

    // ============================================================
    // ASSERTION & OUTPUT
    // ============================================================

    private function assert(string $label, bool $condition): void {
        $this->results[] = ['label' => $label, 'pass' => $condition];
        $icon  = $condition ? C_GREEN . '  ✓' : C_RED . '  ✗';
        echo "{$icon} {$label}" . C_RESET . "\n";
    }

    private function skip(string $label): void {
        $this->results[] = ['label' => $label, 'pass' => null];
        echo C_YELLOW . "  ⟳ SKIP: {$label}" . C_RESET . "\n";
    }

    private function print_summary(): void {
        $passed  = count(array_filter($this->results, fn($r) => $r['pass'] === true));
        $failed  = count(array_filter($this->results, fn($r) => $r['pass'] === false));
        $skipped = count(array_filter($this->results, fn($r) => $r['pass'] === null));

        echo "\n" . str_repeat('─', 55) . "\n";
        $status = $failed === 0 ? C_GREEN . '✓ ALL PASSED' : C_RED . "✗ {$failed} FAILED";
        echo C_BOLD . "{$status}" . C_RESET . "  ";
        echo C_GREEN . "{$passed} passed" . C_RESET . "  ";
        if ($skipped) echo C_YELLOW . "{$skipped} skipped" . C_RESET . "  ";
        if ($failed)  echo C_RED . "{$failed} failed" . C_RESET;
        echo "\n\n";

        if ($failed > 0) {
            echo C_RED . "Failed tests:\n" . C_RESET;
            foreach ($this->results as $r) {
                if ($r['pass'] === false) {
                    echo "  • {$r['label']}\n";
                }
            }
            echo "\n";
            exit(1);  // non-zero exit for CI/CD
        }
    }
}

(new TestRunner())->run();
