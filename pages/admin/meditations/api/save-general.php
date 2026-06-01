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
$generationStatus = in_array($_POST['generation_status'] ?? '', ['ready','pending','failed','generating']) ? $_POST['generation_status'] : 'ready';
$isFreeFirstMonth = (int)($_POST['is_free_first_month'] ?? 0);
$redirect         = $_POST['redirect'] ?? '/admin/meditations/general.php';

if (!$title) { http_response_code(400); exit('Bad request'); }

$uploadDir    = $root . '/assets/audio/meditations/';
$uploadUrlBase = '/assets/audio/meditations/';

function handleAudioUpload(string $fileKey, string $uploadDir, string $uploadUrlBase, ?string $fallbackUrl): ?string
{
    if (empty($_FILES[$fileKey]['tmp_name'])) {
        return $fallbackUrl ?: null;
    }

    $file     = $_FILES[$fileKey];
    $origName = $file['name'];
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if (!in_array($ext, ['mp3', 'mpeg'])) {
        return $fallbackUrl ?: null;
    }
    if ($file['size'] > 100 * 1024 * 1024) { // 100 MB max
        return $fallbackUrl ?: null;
    }

    // Генерируем безопасное имя файла
    $safeName = preg_replace('/[^a-z0-9_\-]/i', '_', pathinfo($origName, PATHINFO_FILENAME));
    $safeName = strtolower($safeName);
    $filename = $safeName . '_' . time() . '.mp3';
    $destPath = $uploadDir . $filename;

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return $fallbackUrl ?: null;
    }

    return $uploadUrlBase . $filename;
}

$fullAudioUrl = handleAudioUpload(
    'full_audio_file',
    $uploadDir,
    $uploadUrlBase,
    trim($_POST['full_audio_url'] ?? '') ?: null
);

$demoAudioUrl = handleAudioUpload(
    'demo_audio_file',
    $uploadDir,
    $uploadUrlBase,
    trim($_POST['demo_audio_url'] ?? '') ?: null
);

// Если загружен full, но нет demo — используем full и как demo
if ($fullAudioUrl && !$demoAudioUrl) {
    $demoAudioUrl = $fullAudioUrl;
}

// Если есть аудио — статус автоматически ready
if ($fullAudioUrl && $generationStatus !== 'ready') {
    $generationStatus = 'ready';
}

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

$sep = str_contains($redirect, '?') ? '&' : '?';
header('Location: ' . $redirect . $sep . 'saved=1');
