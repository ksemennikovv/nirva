<?php
/**
 * api/start-analysis.php — Создаёт сессию анализа (без сохранения сообщения).
 *
 * Только создаёт запись в analysis_sessions и сохраняет ID в сессии.
 * Первое сообщение сохраняется и обрабатывается AI через send-message.php
 * (вызывается из DefaultHero сразу после открытия чата).
 *
 * Ответ: JSON { success, data: { analysis_session_id }, message, error }
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 6);

try {
    require_once $root . '/config/app.php';
    require_once $root . '/config/database.php';
    require_once $root . '/src/services/Database/Database.php';
    require_once $root . '/src/repositories/AnalysisRepository.php';

    $analysisRepo = new AnalysisRepository();
    $sessionId    = $analysisRepo->createSession(null);

    $_SESSION['analysis_session_id'] = $sessionId;

    echo json_encode([
        'success' => true,
        'data'    => ['analysis_session_id' => $sessionId],
        'message' => '',
        'error'   => null,
    ]);

} catch (Throwable $e) {
    error_log('start-analysis.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'data'    => null,
        'message' => 'Ошибка сервера. Попробуйте позже.',
        'error'   => 'server_error',
    ]);
}
