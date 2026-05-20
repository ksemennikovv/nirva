<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/ai.php';
require_once $root . '/config/business.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/repositories/MessageRepository.php';
require_once $root . '/src/repositories/AnalysisRepository.php';
require_once $root . '/src/repositories/ProfileParameterRepository.php';
require_once $root . '/src/services/AI/AIService.php';
require_once $root . '/src/services/Profile/ProfileService.php';

$id          = (int)($_POST['id'] ?? 0);
$sessionId   = (int)($_POST['session_id'] ?? 0);
$instruction = trim($_POST['instruction'] ?? '');

if (!$id || !$sessionId || !$instruction) { http_response_code(400); exit('Bad request'); }

$db      = Database::getConnection();
$msgRepo = new MessageRepository();

// 1. Отклонить старое сообщение
$msgRepo->rejectMessage($id);

// 2. Сохранить коррекцию психолога
$db->prepare(
    'INSERT INTO supervisor_corrections (session_id, rejected_msg_id, instruction) VALUES (?,?,?)'
)->execute([$sessionId, $id, $instruction]);

// 3. Получить контент отклонённого сообщения (чтобы ИИ видел что именно не так)
$rejectedRow = $db->prepare('SELECT content FROM messages WHERE id = ?');
$rejectedRow->execute([$id]);
$rejectedContent = (string)($rejectedRow->fetchColumn() ?? '');

// 4. Собрать историю — только approved сообщения (rejected исключены)
$approvedHistory = $msgRepo->getApprovedMessages($sessionId);

// 5b. Профиль пользователя для промпта
$analysisRepo = new AnalysisRepository();
$session      = $analysisRepo->getSession($sessionId);
$userId       = (int)($session['user_id'] ?? 0);
$profileText  = '';
if ($userId) {
    $profileService = new ProfileService();
    $profileText    = $profileService->formatForPrompt($userId);
}

// 5. Вызвать ИИ с override — коррекция стоит ДО основного промпта
$aiService = new AIService();
$rawReply  = $aiService->sendWithSupervisorOverride($approvedHistory, $instruction, $rejectedContent, $profileText);

// 6. Парсить метаданные нового ответа
$topic = null;
if (preg_match('/\[TOPIC_UPDATE\](.*?)\[\/TOPIC_UPDATE\]/s', $rawReply, $m)) {
    $parsed = json_decode(trim($m[1]), true);
    if (!empty($parsed['topic'])) $topic = $parsed['topic'];
}

$analysisCompleted = false;
$selectedPractice  = null;
$personalTask      = null;
$analysisSummary   = null;
if (preg_match('/\[ANALYSIS_RESULT\](.*?)\[\/ANALYSIS_RESULT\]/s', $rawReply, $m)) {
    $parsed = json_decode(trim($m[1]), true);
    if (!empty($parsed['analysis_completed'])) {
        $analysisCompleted = true;
        $selectedPractice  = $parsed['selected_practice'] ?? null;
        $personalTask      = $parsed['personal_task']     ?? null;
        $analysisSummary   = $parsed['analysis_summary']  ?? null;
    }
}

$cleanReply = preg_replace('/\s*\[TOPIC_UPDATE\].*?\[\/TOPIC_UPDATE\]/s', '', $rawReply);
$cleanReply = trim(preg_replace('/\s*\[ANALYSIS_RESULT\].*?\[\/ANALYSIS_RESULT\]/s', '', $cleanReply));

// 7. Сохранить новый ответ как pending_review
$newMsgId = $msgRepo->saveMessage($sessionId, 'assistant', $cleanReply);
$msgRepo->markPendingReview($newMsgId);

// Обновить pending_metadata
$db->prepare('UPDATE analysis_sessions SET pending_metadata = ? WHERE id = ?')->execute([
    json_encode([
        'msg_id'             => $newMsgId,
        'topic'              => $topic,
        'analysis_completed' => $analysisCompleted,
        'selected_practice'  => $selectedPractice,
        'personal_task'      => $personalTask,
        'analysis_summary'   => $analysisSummary,
    ], JSON_UNESCAPED_UNICODE),
    $sessionId,
]);

$redirect = $_POST['redirect'] ?? '/admin/analyses/supervise.php';
header('Location: ' . $redirect);
