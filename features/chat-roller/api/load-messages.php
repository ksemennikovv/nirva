<?php
/**
 * features/chat-roller/api/load-messages.php — Загружает историю сообщений текущей сессии.
 *
 * Вызывается из ChatRoller.open() при открытии чата.
 * Ответ: JSON { success, data: { messages: [{ role, content }, ...] } }
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 3);

try {
    require_once $root . '/config/app.php';
    require_once $root . '/config/database.php';
    require_once $root . '/src/services/Database/Database.php';

    $body     = json_decode(file_get_contents('php://input'), true);
    $mode     = $body['mode']      ?? 'analysis'; // 'analysis' | 'reflection' | 'diary'
    $entityId = (int)($body['entity_id'] ?? 0);

    if ($mode === 'diary') {
        // Загрузка сообщений дневника
        require_once $root . '/src/repositories/DiaryRepository.php';

        if (!$entityId) {
            echo json_encode(['success' => true, 'data' => ['messages' => []]]);
            exit;
        }

        $diaryRepo = new DiaryRepository();
        $rows      = $diaryRepo->getMessages($entityId);
    } else {
        // Загрузка сообщений разбора или самоисследования
        $sessionId = $entityId ?: (int)($_SESSION['analysis_session_id'] ?? 0);

        if (!$sessionId) {
            echo json_encode(['success' => true, 'data' => ['messages' => [], 'waiting' => false]]);
            exit;
        }

        require_once $root . '/src/repositories/MessageRepository.php';
        $messageRepo = new MessageRepository();

        if ($mode === 'reflection') {
            $rows = $messageRepo->getMessages($sessionId, 'reflection');
        } else {
            // В supervisor mode показываем только одобренные сообщения
            $rows = $messageRepo->getApprovedMessages($sessionId, 'analysis');
        }

        // Если есть pending-сообщения — клиент должен показать typing и начать polling
        $db           = \Database::getConnection();
        $pendingStmt  = $db->prepare(
            'SELECT COUNT(*) FROM messages WHERE analysis_session_id=? AND role="assistant" AND review_status="pending_review"'
        );
        $pendingStmt->execute([$sessionId]);
        $hasPending = (int)$pendingStmt->fetchColumn() > 0;
    }

    $messages = array_map(fn($row) => [
        'role'    => $row['role'],
        'content' => (string)($row['content'] ?? ''),
    ], $rows);

    echo json_encode([
        'success' => true,
        'data'    => [
            'messages'   => $messages,
            'waiting'    => $hasPending ?? false,
            'session_id' => $sessionId ?? null,
        ],
    ]);

} catch (Throwable $e) {
    error_log('load-messages.php error: ' . $e->getMessage());
    echo json_encode(['success' => true, 'data' => ['messages' => []]]);
}
