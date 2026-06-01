<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$id       = (int)($_POST['id']       ?? 0);
$redirect = trim($_POST['redirect']  ?? '/admin/diary/');
$rollback = !empty($_POST['rollback_profile']);
if (!$id) { http_response_code(400); exit('Bad request'); }

$db = Database::getConnection();

// Получаем user_id
$stmt = $db->prepare("SELECT user_id FROM diary_entries WHERE id = ?");
$stmt->execute([$id]);
$entry  = $stmt->fetch(PDO::FETCH_ASSOC);
$userId = $entry ? (int)$entry['user_id'] : null;

// ── Откат психоэмоционального портрета ───────────────────────────────────────
if ($rollback && $userId) {

    // Затронутые параметры
    $phStmt = $db->prepare(
        "SELECT DISTINCT parameter_id FROM profile_parameter_history
         WHERE user_id = ? AND source_type = 'diary' AND source_id = ?"
    );
    $phStmt->execute([$userId, $id]);
    $affectedParams = $phStmt->fetchAll(PDO::FETCH_COLUMN);

    // Удаляем историю этой записи дневника
    $db->prepare(
        "DELETE FROM profile_parameter_history
         WHERE user_id = ? AND source_type = 'diary' AND source_id = ?"
    )->execute([$userId, $id]);

    // Удаляем воспоминания AI из этой записи
    $db->prepare(
        "DELETE FROM user_memories
         WHERE user_id = ? AND source_type = 'diary' AND source_id = ?"
    )->execute([$userId, $id]);

    // Пересчитываем каждый затронутый параметр
    foreach ($affectedParams as $parameterId) {
        _rebuildParameterValue($db, $userId, (int)$parameterId);
    }
}

// ── Удаляем запись дневника ───────────────────────────────────────────────────
$db->prepare("DELETE FROM diary_messages WHERE diary_entry_id = ?")->execute([$id]);
$db->prepare("DELETE FROM diary_entries  WHERE id = ?")->execute([$id]);

$sep = str_contains($redirect, '?') ? '&' : '?';
header('Location: ' . $redirect . $sep . 'deleted=1');

// ─────────────────────────────────────────────────────────────────────────────

function _rebuildParameterValue(PDO $db, int $userId, int $parameterId): void
{
    $stmt = $db->prepare(
        "SELECT event_type, event_data FROM profile_parameter_history
         WHERE user_id = ? AND parameter_id = ?
         ORDER BY created_at ASC, id ASC"
    );
    $stmt->execute([$userId, $parameterId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $values = [];
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
                $values[$val]['evidence_count'] = (int)($data['evidence_count'] ?? $values[$val]['evidence_count'] + 1);
                $values[$val]['updated_at']     = date('c');
            } else {
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
        $db->prepare("DELETE FROM profile_parameter_values WHERE user_id = ? AND parameter_id = ?")
           ->execute([$userId, $parameterId]);
    } else {
        $json = json_encode(array_values($values), JSON_UNESCAPED_UNICODE);
        $db->prepare(
            "INSERT INTO profile_parameter_values (user_id, parameter_id, value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP"
        )->execute([$userId, $parameterId, $json]);
    }
}
