<?php
/**
 * src/services/AI/AIService.php — Сервис взаимодействия с OpenAI API.
 *
 * Отвечает только за:
 *   - загрузку system prompt
 *   - формирование conversation (buildConversation)
 *   - HTTP-запрос к OpenAI (getResponse)
 *   - возврат текста ответа (sendMessage)
 *
 * Зависимости (константы из config/ai.php):
 *   OPENAI_API_KEY, OPENAI_MODEL, OPENAI_TEMPERATURE, OPENAI_MAX_TOKENS
 *
 * Запрещено:
 *   - SQL-запросы (это репозитории)
 *   - HTML / frontend-логика
 *   - сохранение сообщений (это MessageRepository)
 */
class AIService
{
    /** Загруженный system prompt из prompts/analysis-prompt.txt */
    private string $systemPrompt;

    /**
     * Загружает system prompt при создании экземпляра.
     * Путь вычисляется относительно расположения этого файла:
     *   src/services/AI/ → src/services/ → src/ → public_html/ → prompts/
     */
    public function __construct()
    {
        $promptPath         = dirname(__DIR__, 3) . '/prompts/analysis-prompt.txt';
        $this->systemPrompt = file_get_contents($promptPath);
    }

    /**
     * sendMessage() — главный метод: принимает историю, возвращает ответ ассистента.
     * Использует промпт, загруженный в конструкторе (analysis-prompt.txt).
     */
    public function sendMessage(array $history): string
    {
        $conversation = $this->buildConversation($this->systemPrompt, $history);
        return $this->getResponse($conversation);
    }

    /**
     * sendWithPrompt() — отправляет историю с произвольным промптом.
     * Используется для reflection_chat, diary_chat и других режимов.
     *
     * @param array  $history     История сообщений.
     * @param string $promptFile  Имя файла промпта (напр. 'reflection-prompt.txt').
     * @param string $profileText Форматированный профиль для подмешивания в system message (опц.).
     */
    /**
     * sendWithSupervisorOverride() — вызов при reject-and-retry в Supervisor Mode.
     * Коррекция психолога встраивается В НАЧАЛО system prompt — максимальный приоритет.
     */
    public function sendWithSupervisorOverride(
        array  $approvedHistory,
        string $instruction,
        string $rejectedContent,
        string $profileText = ''
    ): string {
        $analysisPrompt = file_get_contents(dirname(__DIR__, 3) . '/prompts/analysis-prompt.txt');

        $overrideBlock = "🚨 SUPERVISOR OVERRIDE — АБСОЛЮТНЫЙ ПРИОРИТЕТ\n\n"
            . "Психолог-куратор отклонил предыдущий ответ и даёт следующую инструкцию:\n\n"
            . "ИНСТРУКЦИЯ: {$instruction}\n\n"
            . "Твой предыдущий ответ (ОТКЛОНЁН — не повторяй его и не возвращайся к его логике):\n"
            . "\"{$rejectedContent}\"\n\n"
            . "Твой следующий ответ ОБЯЗАН буквально следовать инструкции куратора выше.\n"
            . "Это override — он имеет приоритет над стандартным алгоритмом ниже.\n"
            . "Не игнорируй его ни при каких условиях.\n\n"
            . "════════════════════════════════════════\n"
            . "СТАНДАРТНЫЙ АЛГОРИТМ (вторичен, если противоречит override выше):\n"
            . "════════════════════════════════════════\n"
            . $analysisPrompt;

        if ($profileText !== '') {
            $overrideBlock .= "\n\n" . $profileText;
        }

        $conversation = $this->buildConversation($overrideBlock, $approvedHistory);
        return $this->getResponse($conversation);
    }

    public function sendWithPrompt(array $history, string $promptFile, string $profileText = ''): string
    {
        $promptPath = dirname(__DIR__, 3) . '/prompts/' . basename($promptFile);

        if (!file_exists($promptPath)) {
            throw new RuntimeException("Prompt file not found: $promptFile");
        }

        $prompt = file_get_contents($promptPath);

        if ($profileText !== '') {
            $prompt .= "\n\n" . $profileText;
        }

        $conversation = $this->buildConversation($prompt, $history);
        return $this->getResponse($conversation);
    }

    /**
     * checkSafety() — проверяет сообщение пользователя на кризисный контент.
     * Возвращает ['safe' => bool, 'severity' => 'mild'|'medium'|'high'|'critical', 'reason' => string].
     */
    public function checkSafety(string $message): array
    {
        $promptPath = dirname(__DIR__, 3) . '/prompts/safety-filter-prompt.txt';

        if (!file_exists($promptPath)) {
            // Если промпт не создан — пропускаем проверку
            return ['safe' => true, 'severity' => 'none', 'reason' => ''];
        }

        $prompt = file_get_contents($promptPath);

        $conversation = [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user',   'content' => $message],
        ];

        try {
            $raw    = $this->getResponse($conversation);
            $parsed = json_decode(trim($raw), true);

            if (is_array($parsed) && isset($parsed['safe'])) {
                return [
                    'safe'     => (bool)$parsed['safe'],
                    'severity' => $parsed['severity'] ?? 'none',
                    'reason'   => $parsed['reason']   ?? '',
                ];
            }
        } catch (Throwable $e) {
            error_log('AIService::checkSafety error: ' . $e->getMessage());
        }

        return ['safe' => true, 'severity' => 'none', 'reason' => ''];
    }

    /**
     * buildConversation() — строит массив messages для OpenAI API.
     *
     * Структура:
     *   [ { role: 'system', content: systemPrompt }, ...history ]
     *
     * System prompt идёт первым — так OpenAI получает контекст до сообщений пользователя.
     *
     * @param  string $systemPrompt  Текст системного промпта.
     * @param  array  $history       История сообщений [ ['role', 'content'], ... ]
     * @return array                 Готовый messages-массив для API.
     */
    public function buildConversation(string $systemPrompt, array $history): array
    {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($history as $msg) {
            $messages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        return $messages;
    }

    /**
     * getResponse() — отправляет conversation в OpenAI API, возвращает текст ответа.
     *
     * Использует cURL для POST-запроса к /v1/chat/completions.
     * Константы AI_MODEL и AI_TEMPERATURE берутся из config/ai.php.
     *
     * @param  array  $conversation  Массив messages (системный промпт + история).
     * @return string                Текст ответа из choices[0].message.content.
     * @throws RuntimeException      При ошибке сети или неожиданном ответе API.
     */
    public function getResponse(array $conversation): string
    {
        $payload = json_encode([
            'model'       => OPENAI_MODEL,
            'messages'    => $conversation,
            'temperature' => OPENAI_TEMPERATURE,
            'max_tokens'  => OPENAI_MAX_TOKENS,
        ]);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . OPENAI_API_KEY,
            ],
            CURLOPT_TIMEOUT        => 90,
        ]);

        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            error_log('AIService cURL error: ' . $errno);
            throw new RuntimeException('AI request failed');
        }

        $data = json_decode($raw, true);

        // Проверяем наличие ответа в ожидаемой структуре
        $content = $data['choices'][0]['message']['content'] ?? null;

        if ($content === null) {
            error_log('AIService unexpected response: ' . $raw);
            throw new RuntimeException('AI response malformed');
        }

        return trim($content);
    }
}
