<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/services/ImageGeneration/ImageHistoryService.php';

$action       = $_POST['action']       ?? '';
$meditationId = (int)($_POST['meditation_id'] ?? 0);
$historyId    = (int)($_POST['history_id']    ?? 0);

if (!$meditationId) { http_response_code(400); exit('Bad request'); }

$db      = Database::getConnection();
$history = new ImageHistoryService($db);

if ($action === 'rollback' && $historyId) {
    $history->rollbackTo($meditationId, $historyId);
    header('Location: /admin/meditations/edit.php?id=' . $meditationId . '&img=1');
    exit;
}

if ($action === 'delete' && $historyId) {
    $history->deleteRecord($historyId, $meditationId);
    header('Location: /admin/meditations/edit.php?id=' . $meditationId . '&img=1');
    exit;
}

header('Location: /admin/meditations/edit.php?id=' . $meditationId);
