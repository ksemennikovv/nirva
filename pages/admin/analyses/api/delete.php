<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$id           = (int)($_POST['id']             ?? 0);
$redirect     = $_POST['redirect']             ?? '/admin/analyses/';
$rollback     = !empty($_POST['rollback_profile']); // чекбокс отката портрета
if (!$id) { http_response_code(400); exit('Bad request'); }

$db = Database::getConnection();

// Получаем user_id разбора
$stmt = $db->prepare("SELECT user_id FROM analysis_sessions WHERE id = ?");
$stmt->execute([$id]);
$analysis = $stmt->fetch(PDO::FETCH_ASSOC);
$userId   = $analysis ? (int)$analysis['user_id'] : null;

// ── Откат психоэмоционального портрета ───────────────────────────────────────
if ($rollback && $userId) {

    // 1. Находим все parameter_id которые менял этот разбор
    $phStmt = $db->prepare(
        "SELECT DISTINCT parameter_id FROM profile_parameter_history
         WHERE user_id = ? AND source_type IN ('analysis','reflection') AND source_id = ?"
    );
    $phStmt->execute([$userId, $id]);
    $affectedParams = $phStmt->fetchAll(PDO::FETCH_COLUMN);

    // 2. Удаляем историю изменений этого разбора
    $db->prepare(
        "DELETE FROM profile_parameter_history
         WHERE user_id = ? AND source_type IN ('analysis','reflection') AND source_id = ?"
    )->execute([$userId, $id]);

    // 3. Удаляем воспоминания AI из этого разбора
    $db->prepare(
        "DELETE FROM user_memories
         WHERE user_id = ? AND source_type IN ('analysis','reflection') AND source_id = ?"
    )->execute([$userId, $id]);

    // 4. Пересчитываем значения каждого затронутого параметра из оставшейся истории
    foreach ($affectedParams as $parameterId) {
        _rebuildParameterValue($db, $userId, (int)$parameterId);
    }
}

// ── Удаление медитаций разбора ────────────────────────────────────────────────
$medStmt = $db->prepare("SELECT id, full_audio_url, image_url FROM meditations WHERE analysis_id = ?");
$medStmt->execute([$id]);
foreach ($medStmt->fetchAll(PDO::FETCH_ASSOC) as $med) {
    // Удаляем локальные файлы
    foreach (['full_audio_url', 'image_url'] as $col) {
        $url = $med[$col] ?? '';
        if ($url && str_starts_with($url, '/assets/')) {
            $path = $root . $url;
            if (file_exists($path)) @unlink($path);
        }
    }
    $db->prepare("DELETE FROM meditation_listens   WHERE meditation_id = ?")->execute([$med['id']]);
    $db->prepare("DELETE FROM meditation_purchases WHERE meditation_id = ?")->execute([$med['id']]);
    try { $db->prepare("DELETE FROM meditation_image_history WHERE meditation_id = ?")->execute([$med['id']]); } catch (\PDOException $e) {}
}
$db->prepare("DELETE FROM meditations WHERE analysis_id = ?")->execute([$id]);

// ── Удаление сообщений и сессии ───────────────────────────────────────────────
$db->prepare("DELETE FROM supervisor_corrections WHERE session_id = ?")->execute([$id]);
$db->prepare("DELETE FROM messages WHERE analysis_session_id = ?")->execute([$id]);
$db->prepare("DELETE FROM analysis_sessions WHERE id = ?")->execute([$id]);

header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'deleted=1');

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Воспроизводит историю событий параметра и пересохраняет итоговое значение.
 * Если история пуста — удаляет запись из profile_parameter_values.
 */
function _rebuildParameterValue(PDO $db, int $userId, int $parameterId): void
{
    $stmt = $db->prepare(
        "SELECT event_type, event_data FROM profile_parameter_history
         WHERE user_id = ? AND parameter_id = ?
         ORDER BY created_at ASC, id ASC"
    );
    $stmt->execute([$userId, $parameterId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Воспроизводим события: added / updated / removed
    $values = []; // keyed by value string
    foreach ($history as $h) {
        $data = json_decode($h['event_data'], true) ?? [];
        $val  = $data['value'] ?? '';
        if ($val === '') continue;

        if ($h['event_type'] === 'added') {
            $values[$val] = [
                'value'          => $val,
                'confidence'     => (float)($data['confidence'] ?? 0.5),
                'evidence_count' => 1,
                'updated_at'     => date('c'),
            ];
        } elseif ($h['event_type'] === 'updated') {
            if (isset($values[$val])) {
                $values[$val]['confidence']     = (float)($data['new_confidence'] ?? $data['confidence'] ?? $values[$val]['confidence']);
                $values[$val]['evidence_count'] = (int)($data['evidence_count']   ?? $values[$val]['evidence_count'] + 1);
                $values[$val]['updated_at']     = date('c');
            } else {
                // На случай если added было до начала истории
                $values[$val] = [
                    'value'          => $val,
                    'confidence'     => (float)($data['new_confidence'] ?? $data['confidence'] ?? 0.5),
                    'evidence_count' => (int)($data['evidence_count'] ?? 1),
                    'updated_at'     => date('c'),
                ];
            }
        } elseif ($h['event_type'] === 'removed') {
            unset($values[$val]);
        }
    }

    if (empty($values)) {
        $db->prepare(
            "DELETE FROM profile_parameter_values WHERE user_id = ? AND parameter_id = ?"
        )->execute([$userId, $parameterId]);
    } else {
        $json = json_encode(array_values($values), JSON_UNESCAPED_UNICODE);
        $db->prepare(
            "INSERT INTO profile_parameter_values (user_id, parameter_id, value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP"
        )->execute([$userId, $parameterId, $json]);
    }
}
