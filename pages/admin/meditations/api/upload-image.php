<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/services/ImageGeneration/ImageHistoryService.php';

$id = (int)($_POST['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Bad request'); }

$db      = Database::getConnection();
$history = new ImageHistoryService($db);

// Удалить текущую картинку
if (!empty($_POST['delete'])) {
    $stmt = $db->prepare("SELECT image_url FROM meditations WHERE id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetchColumn();
    if ($current) {
        $history->archiveCurrent($id);
        $db->prepare("UPDATE meditations SET image_url = NULL WHERE id = ?")->execute([$id]);
    }
    header('Location: /admin/meditations/edit.php?id=' . $id . '&img=deleted');
    exit;
}

// URL вручную
if (!empty($_POST['image_url'])) {
    $url = trim($_POST['image_url']);
    $history->archiveCurrent($id, 'url');
    $db->prepare("UPDATE meditations SET image_url = ? WHERE id = ?")->execute([$url, $id]);
    header('Location: /admin/meditations/edit.php?id=' . $id . '&img=1');
    exit;
}

// Загрузка файла
if (!empty($_FILES['image_file']['tmp_name'])) {
    $file    = $_FILES['image_file'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','webp'];

    if (!in_array($ext, $allowed) || $file['size'] > 20 * 1024 * 1024) {
        header('Location: /admin/meditations/edit.php?id=' . $id . '&img=error');
        exit;
    }

    $imageDir = $root . '/assets/images/meditations/';
    if (!is_dir($imageDir)) mkdir($imageDir, 0755, true);

    $filename = $id . '_' . time() . '.jpg';
    $destPath = $imageDir . $filename;

    $saved = false;
    if (function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        if ($img) {
            imagejpeg($img, $destPath, 92);
            imagedestroy($img);
            $saved = true;
        }
    }
    if (!$saved) {
        $filename = $id . '_' . time() . '.' . $ext;
        $destPath = $imageDir . $filename;
        move_uploaded_file($file['tmp_name'], $destPath);
    }

    $url = '/assets/images/meditations/' . $filename;
    $history->archiveCurrent($id, 'uploaded');
    $db->prepare("UPDATE meditations SET image_url = ? WHERE id = ?")->execute([$url, $id]);
    header('Location: /admin/meditations/edit.php?id=' . $id . '&img=1');
    exit;
}

header('Location: /admin/meditations/edit.php?id=' . $id);
