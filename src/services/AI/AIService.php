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
     * sendWithPrompt() — отправляет историю с промптом из файла.
     * Используется для reflection_chat и diary_chat.
     */
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
                $severity   = $parsed['severity'] ?? 'none';
                $riskLevel  = $this->severityToRiskLevel((bool)$parsed['safe'], $severity);
                return [
                    'safe'       => (bool)$parsed['safe'],
                    'severity'   => $severity,
                    'reason'     => $parsed['reason'] ?? '',
                    'risk_level' => $riskLevel,
                ];
            }
        } catch (Throwable $e) {
            error_log('AIService::checkSafety error: ' . $e->getMessage());
        }

        return ['safe' => true, 'severity' => 'none', 'reason' => '', 'risk_level' => 'safe'];
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
     * getResponse() — диспетчер: отправляет conversation в нужный провайдер.
     * Провайдер задаётся константой AI_PROVIDER в config/ai.php.
     */
    public function getResponse(array $conversation): string
    {
        $provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'openai';

        return match ($provider) {
            'claude'   => $this->getResponseClaude($conversation),
            'deepseek' => $this->getResponseDeepSeek($conversation),
            default    => $this->getResponseOpenAI($conversation),
        };
    }

    private function getResponseOpenAI(array $conversation): string
    {
        $payload = json_encode([
            'model'       => OPENAI_MODEL,
            'messages'    => $conversation,
            'temperature' => OPENAI_TEMPERATURE,
            'max_tokens'  => OPENAI_MAX_TOKENS,
        ]);

        $raw = $this->curlPost(
            'https://api.openai.com/v1/chat/completions',
            $payload,
            ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY]
        );

        $data    = json_decode($raw, true);
        $content = $data['choices'][0]['message']['content'] ?? null;

        if ($content === null) {
            error_log('AIService OpenAI unexpected response: ' . $raw);
            throw new RuntimeException('AI response malformed');
        }

        return trim($content);
    }

    /**
     * Claude API имеет другую структуру: system передаётся отдельным полем,
     * а не как первый элемент messages с role=system.
     */
    private function getResponseClaude(array $conversation): string
    {
        $systemPrompt = '';
        $messages     = [];

        foreach ($conversation as $msg) {
            if ($msg['role'] === 'system') {
                $systemPrompt = $msg['content'];
            } else {
                $messages[] = $msg;
            }
        }

        $body = ['model' => CLAUDE_MODEL, 'max_tokens' => CLAUDE_MAX_TOKENS, 'messages' => $messages];
        if ($systemPrompt !== '') {
            $body['system'] = $systemPrompt;
        }
        // Claude не принимает temperature ниже 0 или выше 1 — передаём как есть
        $body['temperature'] = CLAUDE_TEMPERATURE;

        $raw = $this->curlPost(
            'https://api.anthropic.com/v1/messages',
            json_encode($body),
            [
                'Content-Type: application/json',
                'x-api-key: ' . CLAUDE_API_KEY,
                'anthropic-version: 2023-06-01',
            ]
        );

        $data    = json_decode($raw, true);
        $content = $data['content'][0]['text'] ?? null;

        if ($content === null) {
            error_log('AIService Claude unexpected response: ' . $raw);
            throw new RuntimeException('AI response malformed');
        }

        return trim($content);
    }

    /**
     * DeepSeek совместим с OpenAI API-форматом — меняем только base URL и ключ.
     */
    private function getResponseDeepSeek(array $conversation): string
    {
        $payload = json_encode([
            'model'       => DEEPSEEK_MODEL,
            'messages'    => $conversation,
            'temperature' => DEEPSEEK_TEMPERATURE,
            'max_tokens'  => DEEPSEEK_MAX_TOKENS,
        ]);

        $raw = $this->curlPost(
            rtrim(DEEPSEEK_API_URL, '/') . '/chat/completions',
            $payload,
            ['Content-Type: application/json', 'Authorization: Bearer ' . DEEPSEEK_API_KEY]
        );

        $data    = json_decode($raw, true);
        $content = $data['choices'][0]['message']['content'] ?? null;

        if ($content === null) {
            error_log('AIService DeepSeek unexpected response: ' . $raw);
            throw new RuntimeException('AI response malformed');
        }

        return trim($content);
    }

    private function severityToRiskLevel(bool $safe, string $severity): string
    {
        if (!$safe) {
            return in_array($severity, ['critical'], true) ? 'psychosis' : 'crisis';
        }
        return match ($severity) {
            'medium' => 'elevated',
            'mild'   => 'safe',
            default  => 'safe',
        };
    }

    private function curlPost(string $url, string $payload, array $headers): string
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 90,
        ]);

        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            error_log('AIService cURL error: ' . $errno . ' url=' . $url);
            throw new RuntimeException('AI request failed');
        }

        return $raw;
    }
}
