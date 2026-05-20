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
            echo json_encode(['success' => true, 'data' => ['messages' => []]]);
            exit;
        }

        require_once $root . '/src/repositories/MessageRepository.php';
        $messageRepo = new MessageRepository();
        $rows        = $messageRepo->getMessages($sessionId, $mode === 'reflection' ? 'reflection' : 'analysis');
    }

    $messages = array_map(fn($row) => [
        'role'    => $row['role'],
        'content' => $row['content'],
    ], $rows);

    echo json_encode([
        'success' => true,
        'data'    => ['messages' => $messages],
    ]);

} catch (Throwable $e) {
    error_log('load-messages.php error: ' . $e->getMessage());
    echo json_encode(['success' => true, 'data' => ['messages' => []]]);
}
