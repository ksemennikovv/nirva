<?php
/**
 * features/chat-roller/api/send-message.php
 *
 * Универсальный обработчик чата. Поддерживает три режима (chat_mode):
 *   analysis_chat   — основной разбор (промпт: analysis-prompt.txt)
 *   reflection_chat — самоисследование после практики (reflection-prompt.txt)
 *   diary_chat      — дневник (diary-prompt.txt)
 *
 * Общая логика:
 *   1. Safety check (кризисный контент)
 *   2. Сохранить сообщение пользователя
 *   3. Подмешать профиль пользователя в system prompt (если авторизован)
 *   4. Получить ответ AI
 *   5. Распарсить специальные блоки по режиму
 *   6. Сохранить чистый ответ
 *   7. Вернуть JSON
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 3);

try {
    require_once $root . '/config/app.php';
    require_once $root . '/config/database.php';
    require_once $root . '/config/ai.php';
    require_once $root . '/config/business.php';
    require_once $root . '/src/services/Database/Database.php';

    // API-эндпоинт: auth.php не используем (он делает redirect, а не JSON).
    // $currentUserId нужен для subscription.php
    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    require $root . '/src/middleware/subscription.php';
    require_once $root . '/src/services/AI/AIService.php';
    require_once $root . '/src/services/Profile/ProfileService.php';
    require_once $root . '/src/repositories/MessageRepository.php';
    require_once $root . '/src/repositories/AnalysisRepository.php';
    require_once $root . '/src/repositories/DiaryRepository.php';

    // ─── Входящие данные ──────────────────────────────────────────────────────

    $body     = json_decode(file_get_contents('php://input'), true);
    $message  = trim($body['message']   ?? '');
    $chatMode = $body['chat_mode']      ?? 'analysis_chat'; // analysis_chat | reflection_chat | diary_chat
    $entityId = (int)($body['entity_id'] ?? 0); // analysis_session_id или diary_entry_id

    if (!$message) {
        echo json_encode(['success' => false, 'data' => null, 'message' => 'Сообщение не может быть пустым', 'error' => 'empty_message']);
        exit;
    }

    $userId = $currentUserId; // установлен выше перед subscription.php

    // Для analysis_chat — берём session_id из PHP-сессии если entity_id не передан
    if ($chatMode === 'analysis_chat') {
        $sessionId = $entityId ?: (int)($_SESSION['analysis_session_id'] ?? 0);
        if (!$sessionId) {
            echo json_encode(['success' => false, 'data' => null, 'message' => 'Сессия анализа не найдена', 'error' => 'no_session']);
            exit;
        }
    }

    // ─── Safety check ─────────────────────────────────────────────────────────

    $aiService = new AIService();
    $safety    = $aiService->checkSafety($message);

    if (!$safety['safe']) {
        $severity = $safety['severity'] ?? 'high';

        $safetyMessages = [
            'critical' => 'Я вижу, что вам сейчас очень тяжело. Пожалуйста, немедленно обратитесь за помощью: позвоните на телефон доверия 8-800-2000-122 (бесплатно) или вызовите скорую помощь.',
            'high'     => 'Я замечаю, что вы переживаете что-то очень серьёзное. Я не могу заменить профессиональную помощь. Пожалуйста, поговорите с психологом или позвоните на линию психологической помощи: 8-800-2000-122.',
            'medium'   => 'Я слышу, что вам сейчас непросто. Если вы чувствуете, что ситуация становится опасной, пожалуйста, обратитесь к специалисту.',
            'mild'     => 'Я слышу вас. Если в какой-то момент вам понадобится профессиональная поддержка, не стесняйтесь обратиться к специалисту.',
        ];

        $replyText = $safetyMessages[$severity] ?? $safetyMessages['medium'];

        // Сохраняем только сообщение пользователя и safety-ответ (в режиме analysis_chat)
        if ($chatMode === 'analysis_chat' && !empty($sessionId)) {
            $messageRepo = new MessageRepository();
            $messageRepo->saveMessage($sessionId, 'user', $message);
            $messageRepo->saveMessage($sessionId, 'assistant', $replyText);
        }

        echo json_encode([
            'success' => true,
            'data'    => [
                'message'  => ['role' => 'assistant', 'content' => $replyText],
                'safety'   => true,
                'severity' => $severity,
            ],
            'message' => '',
            'error'   => null,
        ]);
        exit;
    }

    // ─── Профиль пользователя для промпта ────────────────────────────────────

    $profileText = '';
    if ($userId) {
        $profileService = new ProfileService();
        $profileText    = $profileService->formatForPrompt($userId);
    }

    // ─── Маршрутизация по chat_mode ───────────────────────────────────────────

    if ($chatMode === 'analysis_chat') {
        sendAnalysisMessage($message, (int)$sessionId, $userId, $aiService, $profileText, $root, (bool)($subPaywallActive ?? false), $subscription ?? null);
    } elseif ($chatMode === 'reflection_chat') {
        sendReflectionMessage($message, $entityId, $userId, $aiService, $profileText, $root);
    } elseif ($chatMode === 'diary_chat') {
        sendDiaryMessage($message, $entityId, $userId, $aiService, $profileText, $root);
    } else {
        echo json_encode(['success' => false, 'data' => null, 'message' => 'Неизвестный режим чата', 'error' => 'unknown_mode']);
    }

} catch (Throwable $e) {
    error_log('send-message.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'data' => null, 'message' => $e->getMessage(), 'error' => 'server_error']);
}

// ─── Обработчики по режимам ────────────────────────────────────────────────────

function sendAnalysisMessage(string $message, int $sessionId, int $userId, AIService $ai, string $profileText, string $root, bool $paywallActive = false, ?array $subscription = null): void
{
    require_once $root . '/src/repositories/MessageRepository.php';
    require_once $root . '/src/repositories/AnalysisRepository.php';
    require_once $root . '/src/repositories/SubscriptionRepository.php';

    $analysisRepo = new AnalysisRepository();
    $session      = $analysisRepo->getSession($sessionId);
    $isFirstMsg   = in_array($session['status'] ?? '', ['created', 'active']);

    $messageRepo = new MessageRepository();
    $messageRepo->saveMessage($sessionId, 'user', $message);

    // Разбор начат — засчитываем в лимит подписки (только если не paywall-демо)
    if ($isFirstMsg && $userId && !$paywallActive && $subscription) {
        (new SubscriptionRepository())->incrementUsed((int)$subscription['id']);
    }

    // В supervisor mode используем только одобренные сообщения как контекст
    $history  = BusinessConfig::isSupervisorMode()
        ? $messageRepo->getApprovedMessages($sessionId)
        : $messageRepo->getMessages($sessionId);

    $rawReply  = $profileText
        ? $ai->sendWithPrompt($history, 'analysis-prompt.txt', $profileText)
        : $ai->sendMessage($history);

    // Парсим [TOPIC_UPDATE]
    $topic = null;
    if (preg_match('/\[TOPIC_UPDATE\](.*?)\[\/TOPIC_UPDATE\]/s', $rawReply, $m)) {
        $parsed = json_decode(trim($m[1]), true);
        if (!empty($parsed['topic'])) {
            $topic = $parsed['topic'];
            $_SESSION['analysis_topic'] = $topic;
            (new AnalysisRepository())->updateTopic($sessionId, $topic);
        }
    }

    // Парсим [ANALYSIS_RESULT]
    $analysisCompleted = false;
    $selectedPractice  = null;
    $personalTask      = null;
    $analysisSummary   = null;

    if (preg_match('/\[ANALYSIS_RESULT\](.*?)\[\/ANALYSIS_RESULT\]/s', $rawReply, $m)) {
        $parsed = json_decode(trim($m[1]), true);
        if (!empty($parsed['analysis_completed'])) {
            $analysisCompleted = true;
            $selectedPractice  = $parsed['selected_practice']  ?? null;
            $personalTask      = $parsed['personal_task']      ?? null;
            $analysisSummary   = $parsed['analysis_summary']   ?? null;
        }
    }

    // Очищаем ответ от скрытых блоков
    $cleanReply = preg_replace('/\s*\[TOPIC_UPDATE\].*?\[\/TOPIC_UPDATE\]/s', '', $rawReply);
    $cleanReply = trim(preg_replace('/\s*\[ANALYSIS_RESULT\].*?\[\/ANALYSIS_RESULT\]/s', '', $cleanReply));

    $assistantMsgId = $messageRepo->saveMessage($sessionId, 'assistant', $cleanReply);

    // ── Supervisor Mode ────────────────────────────────────────────────────────
    if (BusinessConfig::isSupervisorMode()) {
        $messageRepo->markPendingReview($assistantMsgId);

        // Сохранить метаданные в сессии — применятся после апрува
        $db = \Database::getConnection();
        $db->prepare('UPDATE analysis_sessions SET pending_metadata = ? WHERE id = ?')->execute([
            json_encode([
                'msg_id'            => $assistantMsgId,
                'topic'             => $topic,
                'analysis_completed'=> $analysisCompleted,
                'selected_practice' => $selectedPractice,
                'personal_task'     => $personalTask,
                'analysis_summary'  => $analysisSummary,
            ], JSON_UNESCAPED_UNICODE),
            $sessionId,
        ]);

        echo json_encode([
            'success' => true,
            'data'    => ['waiting' => true, 'session_id' => $sessionId],
            'message' => '',
            'error'   => null,
        ]);
        return;
    }
    // ──────────────────────────────────────────────────────────────────────────

    if ($analysisCompleted) {
        $practiceTitle = is_array($selectedPractice) ? ($selectedPractice['title'] ?? null) : $selectedPractice;
        $analysisRepo  = new AnalysisRepository();
        $analysisRepo->completeSession($sessionId, $practiceTitle, $analysisSummary, $personalTask);
        $_SESSION['recommended_practice'] = $practiceTitle;
        $_SESSION['personal_task']        = $personalTask;
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            'message'            => ['role' => 'assistant', 'content' => $cleanReply],
            'topic'              => $topic,
            'analysis_completed' => $analysisCompleted,
            'selected_practice'  => $selectedPractice,
            'personal_task'      => $personalTask,
        ],
        'message' => '',
        'error'   => null,
    ]);
}

function sendReflectionMessage(string $message, int $sessionId, int $userId, AIService $ai, string $profileText, string $root): void
{
    require_once $root . '/src/repositories/MessageRepository.php';
    require_once $root . '/src/repositories/AnalysisRepository.php';
    require_once $root . '/src/services/Profile/ProfileService.php';
    require_once $root . '/src/services/Meditation/MeditationService.php';

    $messageRepo = new MessageRepository();
    $messageRepo->saveMessage($sessionId, 'user', $message, 'reflection');

    $history  = $messageRepo->getMessages($sessionId, 'reflection');
    $rawReply = $ai->sendWithPrompt($history, 'reflection-prompt.txt', $profileText);

    // Парсим [REFLECTION_RESULT]
    $reflectionCompleted = false;
    $profileUpdates      = [];
    $newMemories         = [];
    $finalRecommendations = null;
    $reflectionSummary   = null;

    if (preg_match('/\[REFLECTION_RESULT\](.*?)\[\/REFLECTION_RESULT\]/s', $rawReply, $m)) {
        $parsed = json_decode(trim($m[1]), true);
        if ($parsed) {
            $reflectionCompleted  = true;
            $profileUpdates       = $parsed['profile_updates']      ?? [];
            $newMemories          = $parsed['new_memories']         ?? [];
            $finalRecommendations = $parsed['final_recommendations'] ?? null;
            $reflectionSummary    = $parsed['reflection_summary']   ?? null;
        }
    }

    $cleanReply = trim(preg_replace('/\s*\[REFLECTION_RESULT\].*?\[\/REFLECTION_RESULT\]/s', '', $rawReply));
    $messageRepo->saveMessage($sessionId, 'assistant', $cleanReply, 'reflection');

    if ($reflectionCompleted && $userId) {
        $profileService = new ProfileService();

        if (!empty($profileUpdates)) {
            $profileService->updateProfile($userId, $profileUpdates, 'reflection', $sessionId);
        }
        if (!empty($newMemories)) {
            $profileService->addMemories($userId, $newMemories, 'reflection', $sessionId);
        }

        // Завершить разбор
        $analysisRepo = new AnalysisRepository();
        $analysisRepo->completeReflection($sessionId, $reflectionSummary, $finalRecommendations);

        // Запланировать генерацию медитаций
        require_once $root . '/src/services/Meditation/MeditationService.php';
        $meditationService = new MeditationService();
        $meditationService->scheduleGeneration($userId, 'reflection', $sessionId);
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            'message'              => ['role' => 'assistant', 'content' => $cleanReply],
            'reflection_completed' => $reflectionCompleted,
            'final_recommendations' => $finalRecommendations,
        ],
        'message' => '',
        'error'   => null,
    ]);
}

function sendDiaryMessage(string $message, int $entryId, int $userId, AIService $ai, string $profileText, string $root): void
{
    require_once $root . '/src/repositories/DiaryRepository.php';
    require_once $root . '/src/services/Profile/ProfileService.php';

    $diaryRepo = new DiaryRepository();
    $diaryRepo->saveMessage($entryId, 'user', $message);

    $history  = $diaryRepo->getMessages($entryId);
    $rawReply = $ai->sendWithPrompt($history, 'diary-prompt.txt', $profileText);

    // Парсим [DIARY_RESULT]
    $diaryCompleted  = false;
    $profileUpdates  = [];
    $newMemories     = [];
    $summary         = null;
    $suggestedTopics = [];
    $bodyLocation    = null;

    if (preg_match('/\[DIARY_RESULT\](.*?)\[\/DIARY_RESULT\]/s', $rawReply, $m)) {
        $parsed = json_decode(trim($m[1]), true);
        if ($parsed) {
            $diaryCompleted  = true;
            $profileUpdates  = $parsed['profile_updates']  ?? [];
            $newMemories     = $parsed['new_memories']     ?? [];
            $summary         = $parsed['summary']          ?? null;
            $suggestedTopics = $parsed['suggested_topics'] ?? [];
            $bodyLocation    = $parsed['body_location']    ?? null;
        }
    }

    $cleanReply = trim(preg_replace('/\s*\[DIARY_RESULT\].*?\[\/DIARY_RESULT\]/s', '', $rawReply));
    $diaryRepo->saveMessage($entryId, 'assistant', $cleanReply);

    if ($diaryCompleted && $userId) {
        if ($summary) {
            $diaryRepo->updateSummary($entryId, $summary);
        }

        $profileService = new ProfileService();
        if (!empty($profileUpdates)) {
            $profileService->updateProfile($userId, $profileUpdates, 'diary', $entryId);
        }
        if (!empty($newMemories)) {
            $profileService->addMemories($userId, $newMemories, 'diary', $entryId);
        }
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            'message'          => ['role' => 'assistant', 'content' => $cleanReply],
            'diary_completed'  => $diaryCompleted,
            'summary'          => $summary,
            'suggested_topics' => $suggestedTopics,
            'body_location'    => $bodyLocation,
        ],
        'message' => '',
        'error'   => null,
    ]);
}
