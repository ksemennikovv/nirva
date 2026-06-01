<?php
/**
 * Поллинг статуса ревью для пользователя в Supervisor Mode.
 * GET ?session_id=X
 * Возвращает {waiting: true} пока сообщение pending, или готовый ответ после апрува.
 */
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/MessageRepository.php';
require_once $root . '/src/repositories/AnalysisRepository.php';

$sessionId = (int)($_GET['session_id'] ?? 0);
if (!$sessionId) {
    echo json_encode(['success' => false, 'message' => 'session_id required']);
    exit;
}

// Проверяем, что это сессия текущего пользователя
$userId = (int)($_SESSION['user_id'] ?? 0);
$analysisRepo = new AnalysisRepository();
$session = $analysisRepo->getSession($sessionId);
if (!$session || ($userId && (int)$session['user_id'] !== $userId)) {
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    exit;
}

$db      = Database::getConnection();
$msgRepo = new MessageRepository();

// Если есть хоть одно pending — ждём, пока все не будут обработаны
$pendingCount = $db->prepare(
    'SELECT COUNT(*) FROM messages WHERE analysis_session_id=? AND role="assistant" AND review_status="pending_review"'
);
$pendingCount->execute([$sessionId]);
if ((int)$pendingCount->fetchColumn() > 0) {
    echo json_encode(['success' => true, 'data' => ['waiting' => true]]);
    exit;
}

$lastMsg = $msgRepo->getLastAssistantMessage($sessionId);

if (!$lastMsg || $lastMsg['review_status'] === 'rejected' || $lastMsg['review_status'] === null) {
    echo json_encode(['success' => true, 'data' => ['waiting' => true]]);
    exit;
}

if ($lastMsg['review_status'] !== 'approved') {
    echo json_encode(['success' => true, 'data' => ['waiting' => true]]);
    exit;
}

// approved — отдаём сообщение и метаданные
$content = $lastMsg['reviewed_content'] ?? $lastMsg['content'];

// Получить и применить pending_metadata
$row = $db->prepare('SELECT pending_metadata FROM analysis_sessions WHERE id = ?');
$row->execute([$sessionId]);
$meta = json_decode($row->fetchColumn() ?? '{}', true) ?? [];

// Применить завершение разбора если нужно (только если ещё не применено)
if (!empty($meta['analysis_completed']) && $meta['analysis_completed'] === true) {
    $practiceTitle = is_array($meta['selected_practice'])
        ? ($meta['selected_practice']['title'] ?? null)
        : ($meta['selected_practice'] ?? null);
    $analysisRepo->completeSession($sessionId, $practiceTitle, $meta['analysis_summary'] ?? null, $meta['personal_task'] ?? null);
    // Очистить pending_metadata чтобы не применить повторно
    $db->prepare('UPDATE analysis_sessions SET pending_metadata = NULL WHERE id = ?')->execute([$sessionId]);
}

// Обновить тему если есть
if (!empty($meta['topic'])) {
    $analysisRepo->updateTopic($sessionId, $meta['topic']);
}

echo json_encode([
    'success' => true,
    'data'    => [
        'waiting'            => false,
        'message'            => ['role' => 'assistant', 'content' => $content],
        'topic'              => $meta['topic'] ?? null,
        'analysis_completed' => $meta['analysis_completed'] ?? false,
        'selected_practice'  => $meta['selected_practice'] ?? null,
        'personal_task'      => $meta['personal_task'] ?? null,
    ],
]);
