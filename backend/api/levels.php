<?php
// ============================================================
// GET /api/levels?page=1&difficulty=easy&category=tagalog
// Protected. Returns paginated puzzle level catalogue.
// Client caches this locally; server can update content
// without an APK release.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/response.php';

header('Content-Type: application/json');
require_method('GET');

Auth::require();  // just validates token; no user_id needed for catalogue

$page       = max(1, (int)($_GET['page']       ?? 1));
$per_page   = 20;
$offset     = ($page - 1) * $per_page;
$difficulty = $_GET['difficulty'] ?? '';
$category   = $_GET['category']   ?? '';

$allowed_difficulties = ['easy', 'medium', 'hard', ''];
if (!in_array($difficulty, $allowed_difficulties, true)) {
    json_error('Invalid difficulty. Use easy, medium, or hard.');
}

$db = DB::connect();

$where  = ['is_active = 1'];
$params = [];

if ($difficulty !== '') {
    $where[]  = 'difficulty = ?';
    $params[] = $difficulty;
}
if ($category !== '') {
    $where[]  = 'category = ?';
    $params[] = $category;
}

$where_sql = implode(' AND ', $where);

// Total count for pagination
$count_stmt = $db->prepare("SELECT COUNT(*) AS total FROM puzzle_levels WHERE {$where_sql}");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetch()['total'];

// Fetch page
$params[] = $per_page;
$params[] = $offset;
$stmt = $db->prepare(
    "SELECT id, level_number, category, difficulty, coin_reward, grid_data
     FROM puzzle_levels
     WHERE {$where_sql}
     ORDER BY level_number ASC
     LIMIT ? OFFSET ?"
);
$stmt->execute($params);
$levels = $stmt->fetchAll();

// Parse grid_data JSON (stored as JSON column, returned as string by PDO)
foreach ($levels as &$level) {
    $level['grid_data'] = json_decode($level['grid_data'], true);
}
unset($level);

json_ok([
    'levels'      => $levels,
    'pagination'  => [
        'page'       => $page,
        'per_page'   => $per_page,
        'total'      => $total,
        'total_pages'=> (int)ceil($total / $per_page),
    ],
]);
