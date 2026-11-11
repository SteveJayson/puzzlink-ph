<?php
// ============================================================
// PayoutValidationTest.php — Unit tests for number validation
// and payout business logic (no DB needed).
// Run: php backend/tests/PayoutValidationTest.php
// ============================================================

declare(strict_types=1);

define('C_G', "\033[32m"); define('C_R', "\033[31m"); define('C_X', "\033[0m");
$pass = 0; $fail = 0;

function ok(string $label, bool $condition): void {
    global $pass, $fail;
    echo ($condition ? C_G . "  ✓" : C_R . "  ✗ FAIL") . " {$label}" . C_X . "\n";
    $condition ? $pass++ : $fail++;
}

// ---- Philippine mobile number validation ----
function is_valid_ph_number(string $raw): bool {
    $digits = preg_replace('/\D/', '', $raw);
    // Normalize +639XX → 09XX
    if (str_starts_with($digits, '639') && strlen($digits) === 12) {
        $digits = '0' . substr($digits, 2);
    }
    return (bool)preg_match('/^09\d{9}$/', $digits);
}

echo "\n📱 Philippine Number Validation Tests\n";
echo str_repeat('─', 45) . "\n";

ok('09171234567 is valid',         is_valid_ph_number('09171234567'));
ok('09271234567 is valid',         is_valid_ph_number('09271234567'));
ok('09991234567 is valid',         is_valid_ph_number('09991234567'));
ok('+639171234567 is valid',       is_valid_ph_number('+639171234567'));
ok('639171234567 is valid',        is_valid_ph_number('639171234567'));
ok('9171234567 (no 0) is invalid', !is_valid_ph_number('9171234567'));
ok('12345 is invalid',             !is_valid_ph_number('12345'));
ok('08171234567 (08) is invalid',  !is_valid_ph_number('08171234567'));
ok('Empty string is invalid',      !is_valid_ph_number(''));
ok('+1 (555) 234-5678 is invalid', !is_valid_ph_number('+15552345678'));
ok('0917123456 (10 digits) is invalid', !is_valid_ph_number('0917123456'));
ok('09171234567extra is invalid',  !is_valid_ph_number('09171234567extra'));

// ---- E.164 conversion ----
function to_e164(string $number): string {
    $digits = preg_replace('/\D/', '', $number);
    if (str_starts_with($digits, '09') && strlen($digits) === 11) {
        return '+63' . substr($digits, 1);
    }
    if (str_starts_with($digits, '639') && strlen($digits) === 12) {
        return '+' . $digits;
    }
    return '+' . $digits;
}

echo "\n📞 E.164 Conversion Tests (for Xendit API)\n";
echo str_repeat('─', 45) . "\n";

ok('09171234567 → +639171234567', to_e164('09171234567') === '+639171234567');
ok('+639171234567 → +639171234567', to_e164('+639171234567') === '+639171234567');
ok('639171234567 → +639171234567', to_e164('639171234567') === '+639171234567');

// ---- Payout threshold logic ----
function get_threshold(int $completed_payouts): float {
    return $completed_payouts === 0 ? 50.0 : 300.0;
}

echo "\n💰 Payout Threshold Tests\n";
echo str_repeat('─', 45) . "\n";

ok('First cashout threshold = ₱50',   get_threshold(0) === 50.0);
ok('Second cashout threshold = ₱300', get_threshold(1) === 300.0);
ok('Tenth cashout threshold = ₱300',  get_threshold(9) === 300.0);

// ---- Payout name validation ----
function is_valid_name(string $name): bool {
    $len = strlen(trim($name));
    return $len >= 2 && $len <= 100;
}

echo "\n👤 Account Name Validation Tests\n";
echo str_repeat('─', 45) . "\n";

ok('"Juan dela Cruz" is valid',   is_valid_name('Juan dela Cruz'));
ok('"JD" is valid (2 chars)',     is_valid_name('JD'));
ok('"J" is invalid (1 char)',     !is_valid_name('J'));
ok('Empty string is invalid',     !is_valid_name(''));
ok('101-char string is invalid',  !is_valid_name(str_repeat('A', 101)));

// ---- Coin debit calculation ----
function coins_for_payout(float $amount_php): int {
    return (int)ceil($amount_php * 1000);
}

echo "\n🪙 Coin Debit Calculation Tests\n";
echo str_repeat('─', 45) . "\n";

ok('₱50 payout debits 50,000 coins',  coins_for_payout(50.0)  === 50000);
ok('₱300 payout debits 300,000 coins', coins_for_payout(300.0) === 300000);

// ---- Summary ----
echo str_repeat('─', 45) . "\n";
$status = $fail === 0
    ? C_G . "✓ ALL {$pass} TESTS PASSED" . C_X
    : C_R . "✗ {$fail} FAILED / {$pass} PASSED" . C_X;
echo "{$status}\n\n";

exit($fail > 0 ? 1 : 0);
