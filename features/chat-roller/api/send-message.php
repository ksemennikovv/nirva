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

    $body      = json_decode(file_get_contents('php://input'), true);
    $message   = trim($body['message']    ?? '');
    $chatMode  = $body['chat_mode']       ?? 'analysis_chat'; // analysis_chat | reflection_chat | diary_chat
    $entityId  = (int)($body['entity_id'] ?? 0); // analysis_session_id или diary_entry_id
    $diaryMode = $body['diary_mode']      ?? null; // vent | reflection (только для diary_chat)

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

    $aiService = new AIService();

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
        sendDiaryMessage($message, $entityId, $userId, $aiService, $profileText, $root, $diaryMode);
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

    // Считаем подписку при первом сообщении
    if ($isFirstMsg && $userId && !$paywallActive && $subscription) {
        (new SubscriptionRepository())->incrementUsed((int)$subscription['id']);
    }

    // ── Стартовый заголовок из первого сообщения ─────────────────────────────
    $topic = null;
    if ($isFirstMsg) {
        $topic = generateAnalysisTopic($message, $ai);
        $analysisRepo->updateTopic($sessionId, $topic);
        $_SESSION['analysis_topic'] = $topic;
    }

    // ── Промпт + история ─────────────────────────────────────────────────────
    $systemPrompt = file_get_contents($root . '/prompts/analysis-prompt.txt');
    if ($profileText !== '') {
        $systemPrompt .= "\n\n" . $profileText;
    }

    $history      = $messageRepo->getMessages($sessionId, 'analysis');
    $conversation = $ai->buildConversation($systemPrompt, $history);
    $rawReply     = $ai->getResponse($conversation);

    // ── Парсим [ANALYSIS_RESULT] ──────────────────────────────────────────────
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
            // Финальная тема разбора — может отличаться от стартовой
            if (!empty($parsed['topic'])) {
                $topic = $parsed['topic'];
                $analysisRepo->updateTopic($sessionId, $topic);
                $_SESSION['analysis_topic'] = $topic;
            }
        }
    }

    // ── Очищаем ответ от тега ─────────────────────────────────────────────────
    $cleanReply = trim(preg_replace('/\s*\[ANALYSIS_RESULT\].*?\[\/ANALYSIS_RESULT\]/s', '', $rawReply));

    if ($cleanReply === '' && $analysisCompleted) {
        $practiceLabel = is_array($selectedPractice) ? ($selectedPractice['title'] ?? '') : ($selectedPractice ?? '');
        $cleanReply = $practiceLabel
            ? "Ты большой молодец. Я подобрал для тебя практику «{$practiceLabel}» — она поможет проработать то, что мы обнаружили."
            : 'Ты прошёл через это. Практика готова — она будет здесь для тебя.';
    }

    $messageRepo->saveMessage($sessionId, 'assistant', $cleanReply);

    if ($analysisCompleted) {
        $practiceTitle = is_array($selectedPractice) ? ($selectedPractice['title'] ?? null) : $selectedPractice;
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

function generateAnalysisTopic(string $message, AIService $ai): string
{
    $conversation = [
        ['role' => 'system', 'content' =>
            'Дай заголовок для психологического разбора в 3-5 слов. ' .
            'Только суть проблемы или темы — без вводных слов приветствия, без кавычек. ' .
            'Примеры: "Страх потерять работу", "Конфликт с матерью", "Тревога перед переездом". ' .
            'Отвечай только заголовком, ничего больше.'],
        ['role' => 'user', 'content' => $message],
    ];
    try {
        $title = $ai->getResponse($conversation);
        return mb_substr(trim($title), 0, 80);
    } catch (Throwable $e) {
        error_log('generateAnalysisTopic error: ' . $e->getMessage());
        return '';
    }
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

function sendDiaryMessage(string $message, int $entryId, int $userId, AIService $ai, string $profileText, string $root, ?string $diaryMode = null): void
{
    require_once $root . '/src/repositories/DiaryRepository.php';
    require_once $root . '/src/services/Profile/ProfileService.php';

    $diaryRepo = new DiaryRepository();
    $diaryRepo->saveMessage($entryId, 'user', $message);

    $promptFile = match($diaryMode) {
        'vent'       => 'diary-prompt-vent.txt',
        'reflection' => 'diary-prompt-reflection.txt',
        default      => 'diary-prompt.txt',
    };

    $history  = $diaryRepo->getMessages($entryId);
    $rawReply = $ai->sendWithPrompt($history, $promptFile, $profileText);

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
