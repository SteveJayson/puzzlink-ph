<?php
// ============================================================
// seed_levels.php — Run via CLI to populate puzzle_levels
// Usage: php database/seed_levels.php
//
// Generates 500 word-search puzzles across 3 difficulty tiers.
// Words are sourced from curated Filipino-English vocabulary
// sets relevant to everyday life in the Philippines.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/db.php';

$db = DB::connect();

// ============================================================
// WORD POOLS — categorized by difficulty
// ============================================================

$WORD_POOLS = [
    'easy' => [
        // Everyday PH life
        ['GCASH', 'COINS', 'PLAY', 'WIN', 'MALL'],
        ['LOAD', 'WIFI', 'PHONE', 'APP', 'FREE'],
        ['LOTTO', 'BAON', 'FOOD', 'RIDE', 'SAVE'],
        ['WORK', 'HOME', 'GAME', 'EARN', 'CASH'],
        ['LOVE', 'TOWN', 'FISH', 'RICE', 'SHOP'],
    ],
    'medium' => [
        ['REWARD', 'PUZZLE', 'POINTS', 'LEVEL', 'BONUS'],
        ['MARKET', 'JOLLIBEE', 'BUDGET', 'SALARY', 'BILLS'],
        ['TRAVEL', 'TICKET', 'BRIDGE', 'DRIVER', 'ROUTE'],
        ['SCHOOL', 'LAPTOP', 'COFFEE', 'CHURCH', 'BARYO'],
        ['UPLOAD', 'STREAM', 'SIGNAL', 'MOBILE', 'QUOTA'],
    ],
    'hard' => [
        ['CASHOUT', 'BALANCE', 'PAYMENT', 'SAVINGS', 'DIGITAL'],
        ['OFFERWALL', 'DOWNLOAD', 'COMPLETE', 'PROGRESS', 'TUTORIAL'],
        ['PROVINCE', 'KAIBIGAN', 'PROBINSYA', 'BARANGAY', 'PALENGKE'],
        ['FREELANCE', 'BUSINESS', 'INTERNET', 'DISCOUNT', 'VOUCHER'],
        ['BIRTHDAY', 'FESTIVAL', 'TOGETHER', 'HARDWORK', 'PRACTICE'],
    ],
];

$CATEGORIES = ['general', 'tagalog', 'lifestyle', 'finance', 'entertainment'];

$DIFFICULTY_MAP = [
    'easy'   => ['tiers' => [1, 167],   'grid_size' => 7, 'coin_reward' => 15],
    'medium' => ['tiers' => [168, 334], 'grid_size' => 9, 'coin_reward' => 20],
    'hard'   => ['tiers' => [335, 500], 'grid_size' => 11,'coin_reward' => 30],
];

// ============================================================
// GENERATOR
// ============================================================

function generate_wordsearch(array $words, int $size): array {
    $grid    = [];
    $placed  = [];
    $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    // Init grid with dots
    for ($r = 0; $r < $size; $r++) {
        $grid[$r] = array_fill(0, $size, '.');
    }

    $directions = [
        [0, 1], [0, -1], [1, 0], [-1, 0],
        [1, 1], [1, -1], [-1, 1], [-1, -1],
    ];

    foreach ($words as $word) {
        $word  = strtoupper($word);
        $len   = strlen($word);
        $tries = 0;
        $ok    = false;

        while ($tries < 100 && !$ok) {
            $tries++;
            $dir = $directions[random_int(0, 7)];
            $r   = random_int(0, $size - 1);
            $c   = random_int(0, $size - 1);

            // Check if word fits
            $fits = true;
            for ($i = 0; $i < $len; $i++) {
                $nr = $r + $dir[0] * $i;
                $nc = $c + $dir[1] * $i;
                if ($nr < 0 || $nr >= $size || $nc < 0 || $nc >= $size) {
                    $fits = false;
                    break;
                }
                $existing = $grid[$nr][$nc];
                if ($existing !== '.' && $existing !== $word[$i]) {
                    $fits = false;
                    break;
                }
            }

            if ($fits) {
                for ($i = 0; $i < $len; $i++) {
                    $grid[$r + $dir[0] * $i][$c + $dir[1] * $i] = $word[$i];
                }
                $placed[] = $word;
                $ok       = true;
            }
        }
    }

    // Fill remaining dots with random letters
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($grid[$r][$c] === '.') {
                $grid[$r][$c] = $letters[random_int(0, 25)];
            }
        }
    }

    return ['grid' => $grid, 'words' => $placed];
}

// ============================================================
// SEEDING
// ============================================================

$total    = 0;
$skipped  = 0;
$stmt_check = $db->prepare('SELECT id FROM puzzle_levels WHERE level_number = ? LIMIT 1');
$stmt_insert = $db->prepare(
    'INSERT INTO puzzle_levels (level_number, category, difficulty, grid_data, coin_reward)
     VALUES (?, ?, ?, ?, ?)'
);

foreach ($DIFFICULTY_MAP as $difficulty => $config) {
    [$start, $end] = $config['tiers'];
    $grid_size     = $config['grid_size'];
    $coin_reward   = $config['coin_reward'];
    $pool          = $WORD_POOLS[$difficulty];

    echo "Generating {$difficulty} levels {$start}–{$end}...\n";

    for ($level = $start; $level <= $end; $level++) {
        // Check if already exists
        $stmt_check->execute([$level]);
        if ($stmt_check->fetch()) {
            $skipped++;
            continue;
        }

        // Pick a word set (rotating through pool)
        $word_set  = $pool[($level - 1) % count($pool)];
        $category  = $CATEGORIES[($level - 1) % count($CATEGORIES)];
        $grid_data = generate_wordsearch($word_set, $grid_size);

        $stmt_insert->execute([
            $level,
            $category,
            $difficulty,
            json_encode($grid_data),
            $coin_reward,
        ]);
        $total++;

        if ($level % 50 === 0) {
            echo "  → Level {$level} done\n";
        }
    }
}

echo "\n✓ Seeded {$total} levels. Skipped {$skipped} existing.\n";
