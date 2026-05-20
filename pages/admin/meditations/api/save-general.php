<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$id               = (int)($_POST['id'] ?? 0);
$title            = trim($_POST['title'] ?? '');
$categoryId       = (int)($_POST['category_id'] ?? 0) ?: null;
$price            = max(0, (float)($_POST['price'] ?? 0));
$description      = trim($_POST['description'] ?? '') ?: null;
$demoAudioUrl     = trim($_POST['demo_audio_url'] ?? '') ?: null;
$fullAudioUrl     = trim($_POST['full_audio_url'] ?? '') ?: null;
$generationStatus = in_array($_POST['generation_status'] ?? '', ['ready','pending','failed','generating']) ? $_POST['generation_status'] : 'ready';
$isFreeFirstMonth = (int)($_POST['is_free_first_month'] ?? 0);
$redirect         = $_POST['redirect'] ?? '/admin/meditations/general.php';

if (!$title) { http_response_code(400); exit('Bad request'); }

$db = Database::getConnection();

if ($id) {
    $db->prepare("UPDATE meditations SET
        title=?, category_id=?, price=?, description=?,
        demo_audio_url=?, full_audio_url=?,
        generation_status=?, is_free_first_month=?
        WHERE id=? AND type='general'")
       ->execute([$title, $categoryId, $price, $description,
                  $demoAudioUrl, $fullAudioUrl,
                  $generationStatus, $isFreeFirstMonth, $id]);
} else {
    $db->prepare("INSERT INTO meditations
        (type, topic_type, title, category_id, price, description,
         demo_audio_url, full_audio_url, generation_status, is_free_first_month)
        VALUES ('general','general',?,?,?,?,?,?,?,?)")
       ->execute([$title, $categoryId, $price, $description,
                  $demoAudioUrl, $fullAudioUrl,
                  $generationStatus, $isFreeFirstMonth]);
}

header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'saved=1');
