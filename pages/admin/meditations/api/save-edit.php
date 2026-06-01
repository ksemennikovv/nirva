<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$id = (int)($_POST['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Bad request'); }

$db   = Database::getConnection();
$stmt = $db->prepare("SELECT type FROM meditations WHERE id = ?");
$stmt->execute([$id]);
$med = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$med) { http_response_code(404); exit('Not found'); }

$title           = trim($_POST['title']            ?? '') ?: null;
$topic           = trim($_POST['topic']            ?? '') ?: null;
$description     = trim($_POST['description']      ?? '') ?: null;
$personalContext = trim($_POST['personal_context'] ?? '') ?: null;
$genStatus       = in_array($_POST['generation_status'] ?? '', ['ready','pending','generating','failed']) ? $_POST['generation_status'] : 'ready';
$type            = in_array($_POST['type']          ?? '', ['general','personal']) ? $_POST['type'] : $med['type'];

// Аудио: загрузка файлов
$audioDir     = $root . '/assets/audio/meditations/';
$audioDirBase = '/assets/audio/meditations/';

function handleAudioUpload(string $key, string $dir, string $base, ?string $fallback): ?string {
    if (empty($_FILES[$key]['tmp_name'])) return $fallback ?: null;
    $file = $_FILES[$key];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp3','mpeg'])) return $fallback ?: null;
    if ($file['size'] > 100 * 1024 * 1024) return $fallback ?: null;
    $safe = preg_replace('/[^a-z0-9_\-]/i', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $name = strtolower($safe) . '_' . time() . '.mp3';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) return $fallback ?: null;
    return $base . $name;
}

$fullAudioUrl = handleAudioUpload('full_audio_file', $audioDir, $audioDirBase, trim($_POST['full_audio_url'] ?? '') ?: null);
$demoAudioUrl = handleAudioUpload('demo_audio_file', $audioDir, $audioDirBase, trim($_POST['demo_audio_url'] ?? '') ?: null);

$sets = "title=?, topic=?, description=?, personal_context=?, full_audio_url=?, demo_audio_url=?, generation_status=?, type=?";
$vals = [$title, $topic, $description, $personalContext, $fullAudioUrl, $demoAudioUrl, $genStatus, $type];

if ($med['type'] === 'general') {
    $categoryId = (int)($_POST['category_id'] ?? 0) ?: null;
    $price      = max(0, (float)($_POST['price'] ?? 0));
    $sets .= ", category_id=?, price=?";
    $vals[] = $categoryId;
    $vals[] = $price;
}

$vals[] = $id;
$db->prepare("UPDATE meditations SET $sets WHERE id=?")->execute($vals);

header('Location: /admin/meditations/edit.php?id=' . $id . '&saved=1');
