<?php
/**
 * meditations/api/available.php
 * Все медитации доступные текущему пользователю для плеера.
 */
session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/assets/php/helpers.php';
require_once $root . '/src/middleware/auth.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/SubscriptionRepository.php';

$db = Database::getConnection();

// Проверяем первый месяц (бесплатное окно) — только если нет подписки
$subRepo      = new SubscriptionRepository();
$subscription = $subRepo->getActive($currentUserId);
$isFirstMonth = false;
if (!$subscription) {
    $stmtUser = $db->prepare('SELECT created_at FROM users WHERE id = ?');
    $stmtUser->execute([$currentUserId]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
    $isFirstMonth = $user && strtotime($user['created_at']) > (time() - BusinessConfig::meditationFreeWindowSeconds());
}

// Последнее прослушивание на медитацию
$stmtLL = $db->prepare(
    'SELECT meditation_id, MAX(listened_at) AS last_listened_at
     FROM meditation_listens WHERE user_id = ?
     GROUP BY meditation_id'
);
$stmtLL->execute([$currentUserId]);
$lastListened = $stmtLL->fetchAll(PDO::FETCH_KEY_PAIR);

// Цвета категорий (те же что в meditations/index.php)
$categoryColors = [
    'confidence' => ['#6c5ce7', '#a29bfe'],
    'family'     => ['#e17055', '#fab1a0'],
    'money'      => ['#00b894', '#55efc4'],
    'health'     => ['#0984e3', '#74b9ff'],
    'children'   => ['#fd79a8', '#fdcfe8'],
];

$items = [];

// Проверяем наличие колонки image_url (миграция 011 может быть не выполнена)
$hasImageUrl = false;
try {
    $db->query("SELECT image_url FROM meditations LIMIT 1");
    $hasImageUrl = true;
} catch (\PDOException $e) {}
$imgColPersonal = $hasImageUrl ? ', m.image_url' : '';
$imgColGeneral  = $hasImageUrl ? 'm.image_url, ' : '';

// ── Личные медитации (принадлежат пользователю, не истекли) ──────────────────
// Личные медитации создаются автоматически и не требуют покупки
$stmtP = $db->prepare(
    "SELECT m.id, m.title, m.description, m.full_audio_url, m.demo_audio_url $imgColPersonal
     FROM meditations m
     WHERE m.user_id = ?
       AND m.type = 'personal'
       AND m.generation_status = 'ready'
       AND m.full_audio_url IS NOT NULL
       AND (m.expires_at IS NULL OR m.expires_at > NOW())
     ORDER BY m.id DESC"
);
$stmtP->execute([$currentUserId]);
foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $items[] = [
        'id'               => (int)$m['id'],
        'title'            => $m['title'] ?? '',
        'description'      => $m['description'] ?? '',
        'full_audio_url'   => $m['full_audio_url'] ?? '',
        'demo_audio_url'   => $m['demo_audio_url'] ?? '',
        'image_url'        => $hasImageUrl ? ($m['image_url'] ?? '') : '',
        'gradient'         => 'linear-gradient(145deg,#a29bfe,#6c5ce7)',
        'free'             => true,
        'type'             => 'personal',
        'last_listened_at' => $lastListened[(int)$m['id']] ?? null,
    ];
}

// ── Общие медитации (куплены ИЛИ первый месяц + is_free_first_month) ──────────
$stmtG = $db->prepare(
    "SELECT m.id, m.title, m.description, m.full_audio_url, m.demo_audio_url,
            {$imgColGeneral} m.is_free_first_month,
            COALESCE(mp.id, 0) AS is_purchased,
            c.slug AS category_slug
     FROM meditations m
     JOIN meditation_categories c ON c.id = m.category_id
     LEFT JOIN meditation_purchases mp ON mp.meditation_id = m.id AND mp.user_id = ?
     WHERE m.type = 'general'
       AND m.user_id IS NULL
       AND m.generation_status = 'ready'
     ORDER BY c.sort_order, m.id ASC"
);
$stmtG->execute([$currentUserId]);
foreach ($stmtG->fetchAll(PDO::FETCH_ASSOC) as $m) {
    // Доступна если: куплена, или первый месяц + is_free_first_month, или есть активная подписка
    $isFree = $m['is_purchased']
        || ($isFirstMonth && $m['is_free_first_month'])
        || ($subscription !== null);
    if (!$isFree) continue;

    $slug   = $m['category_slug'] ?? '';
    $colors = $categoryColors[$slug] ?? ['#6c5ce7', '#a29bfe'];
    $items[] = [
        'id'               => (int)$m['id'],
        'title'            => $m['title'] ?? '',
        'description'      => $m['description'] ?? '',
        'full_audio_url'   => $m['full_audio_url'] ?? '',
        'demo_audio_url'   => $m['demo_audio_url'] ?? '',
        'image_url'        => $hasImageUrl ? ($m['image_url'] ?? '') : '',
        'gradient'         => 'linear-gradient(145deg,' . $colors[0] . ',' . $colors[1] . ')',
        'free'             => true,
        'type'             => 'general',
        'last_listened_at' => $lastListened[(int)$m['id']] ?? null,
    ];
}

echo json_encode(['success' => true, 'items' => $items]);
