<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$id       = (int)($_POST['id'] ?? 0);
$redirect = trim($_POST['redirect'] ?? '/admin/meditations/');
if (!$id) { http_response_code(400); exit('Bad request'); }

$db = Database::getConnection();

// Удаляем все связанные записи
$db->prepare("DELETE FROM meditation_listens   WHERE meditation_id = ?")->execute([$id]);
$db->prepare("DELETE FROM meditation_purchases WHERE meditation_id = ?")->execute([$id]);
try {
    $db->prepare("DELETE FROM meditation_image_history WHERE meditation_id = ?")->execute([$id]);
} catch (\PDOException $e) {}

// Удаляем локальные аудио и картинку
$stmt = $db->prepare("SELECT full_audio_url, demo_audio_url, image_url FROM meditations WHERE id = ?");
$stmt->execute([$id]);
$med = $stmt->fetch(PDO::FETCH_ASSOC);
if ($med) {
    foreach (['full_audio_url', 'demo_audio_url', 'image_url'] as $col) {
        $url = $med[$col] ?? '';
        if ($url && str_starts_with($url, '/assets/')) {
            $path = $root . $url;
            if (file_exists($path)) @unlink($path);
        }
    }
}

$db->prepare("DELETE FROM meditations WHERE id = ?")->execute([$id]);

header('Location: ' . $redirect . '?deleted=1');
