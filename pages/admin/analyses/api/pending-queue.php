<?php
/**
 * Возвращает JSON с текущей очередью разборов на модерации.
 * Используется для асинхронного обновления страницы надзора.
 */
session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$db = Database::getConnection();

$focusSessionId = (int)($_GET['session_id'] ?? 0);

// Список сессий с pending
$sessions = $db->query("
    SELECT a.id AS session_id, a.topic, a.user_id,
           COALESCE(u.email, '—') AS email,
           COUNT(m.id) AS pending_count,
           MIN(m.created_at) AS oldest_pending
    FROM messages m
    JOIN analysis_sessions a ON a.id = m.analysis_session_id
    LEFT JOIN users u ON u.id = a.user_id
    WHERE m.role = 'assistant' AND m.review_status = 'pending_review'
    GROUP BY a.id
    ORDER BY oldest_pending ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Последнее pending сообщение текущей сессии (для обнаружения нового)
$latestMsgId = null;
if ($focusSessionId) {
    $row = $db->prepare("
        SELECT id FROM messages
        WHERE analysis_session_id = ? AND role = 'assistant' AND review_status = 'pending_review'
        ORDER BY id DESC LIMIT 1
    ");
    $row->execute([$focusSessionId]);
    $latestMsgId = $row->fetchColumn() ?: null;
}

echo json_encode([
    'sessions'      => $sessions,
    'total'         => count($sessions),
    'latest_msg_id' => $latestMsgId,
]);
