<?php
session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 2);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/AnalysisRepository.php';

$body = json_decode(file_get_contents('php://input'), true);
$analysisSessionId = (int)($body['analysis_session_id'] ?? 0);

if (!$analysisSessionId) {
    echo json_encode(['success' => false, 'error' => 'no_id']);
    exit;
}

$repo    = new AnalysisRepository();
$session = $repo->getSession($analysisSessionId);

// Восстанавливаем только анонимные (user_id IS NULL) незавершённые сессии
if (
    !$session ||
    $session['user_id'] !== null ||
    in_array($session['status'], ['completed', 'abandoned', 'closed'])
) {
    echo json_encode(['success' => false, 'error' => 'not_restorable']);
    exit;
}

// Восстанавливаем PHP-сессию
$_SESSION['analysis_session_id'] = $analysisSessionId;

echo json_encode([
    'success' => true,
    'state'   => 'unfinished-analysis',
    'topic'   => $session['topic'] ?? '',
]);
