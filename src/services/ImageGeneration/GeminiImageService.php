<?php

/**
 * Генерация изображений через NanoBanana API
 * Поддерживает три модели: nano-banana, nano-banana-2, nano-banana-pro
 */
class GeminiImageService
{
    private const BASE_URL   = 'https://api.nanobananaapi.ai/api/v1/nanobanana';
    private const POLL_MAX   = 40;
    private const POLL_SLEEP = 3;

    private string $apiKey;
    private string $model;
    private string $aspectRatio;
    private string $resolution;
    private string $imageDir;
    private string $imageUrlBase;

    public function __construct(
        string $model       = '',
        string $aspectRatio = '9:16',
        string $resolution  = '1K'
    ) {
        $this->apiKey       = defined('NANOBANANA_API_KEY')        ? NANOBANANA_API_KEY        : '';
        $this->imageDir     = defined('MEDITATION_IMAGE_DIR')      ? MEDITATION_IMAGE_DIR      : dirname(__DIR__, 3) . '/assets/images/meditations/';
        $this->imageUrlBase = defined('MEDITATION_IMAGE_URL_BASE') ? MEDITATION_IMAGE_URL_BASE : '/assets/images/meditations/';

        // Модель из настроек или дефолт
        if (!$model && class_exists('BusinessConfig')) {
            $model = BusinessConfig::setting('imagen_model', 'nano-banana-2');
        }
        $this->model       = $model ?: 'nano-banana-2';
        $this->aspectRatio = $aspectRatio;
        $this->resolution  = $resolution;
    }

    public function generate(string $prompt, int $meditationId): ?string
    {
        if (!$this->apiKey) {
            error_log('[GeminiImageService] NANOBANANA_API_KEY не задан');
            return null;
        }

        $taskId = $this->submitTask($prompt);
        if (!$taskId) return null;

        $imageUrl = $this->pollResult($taskId);
        if (!$imageUrl) return null;

        return $this->downloadAndSave($imageUrl, $meditationId);
    }

    private function submitTask(string $prompt): ?string
    {
        // Формируем payload в зависимости от модели
        if ($this->model === 'nano-banana-2') {
            $endpoint = self::BASE_URL . '/generate-2';
            $payload  = json_encode([
                'prompt'      => $prompt,
                'imageUrls'   => [],
                'aspectRatio' => $this->aspectRatio,
                'resolution'  => $this->resolution,
                'outputFormat'=> 'jpg',
            ]);
        } elseif ($this->model === 'nano-banana-pro') {
            $endpoint = self::BASE_URL . '/generate-pro';
            $payload  = json_encode([
                'prompt'      => $prompt,
                'imageUrls'   => [],
                'aspectRatio' => $this->aspectRatio,
                'resolution'  => $this->resolution,
                'outputFormat'=> 'jpg',
            ]);
        } else {
            // nano-banana (base)
            $endpoint = self::BASE_URL . '/generate';
            $payload  = json_encode([
                'prompt'    => $prompt,
                'type'      => 'TEXTTOIAMGE',
                'numImages' => 1,
            ]);
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) { error_log("[GeminiImageService] cURL: $curlErr"); return null; }
        if ($httpCode !== 200) { error_log("[GeminiImageService] HTTP $httpCode: $response"); return null; }

        $data   = json_decode($response, true);
        $taskId = $data['data']['taskId'] ?? null;

        if (!$taskId) { error_log("[GeminiImageService] Нет taskId: $response"); return null; }
        return $taskId;
    }

    private function pollResult(string $taskId): ?string
    {
        for ($i = 0; $i < self::POLL_MAX; $i++) {
            sleep(self::POLL_SLEEP);

            $ch = curl_init(self::BASE_URL . '/record-info?taskId=' . urlencode($taskId));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->apiKey],
            ]);

            $response = curl_exec($ch);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($curlErr) { error_log("[GeminiImageService] poll cURL: $curlErr"); continue; }

            $data  = json_decode($response, true);
            $inner = $data['data'] ?? [];
            $flag  = $inner['successFlag'] ?? -1;

            if ($flag === 1) return $inner['response']['resultImageUrl'] ?? null;
            if ($flag === 2 || $flag === 3) {
                error_log("[GeminiImageService] Task failed flag=$flag: " . ($inner['errorMessage'] ?? ''));
                return null;
            }
        }

        error_log("[GeminiImageService] Timeout taskId=$taskId");
        return null;
    }

    private function downloadAndSave(string $remoteUrl, int $meditationId): ?string
    {
        if (!is_dir($this->imageDir)) mkdir($this->imageDir, 0755, true);

        $imageBytes = file_get_contents($remoteUrl);
        if ($imageBytes === false || strlen($imageBytes) < 1000) {
            error_log("[GeminiImageService] Не удалось скачать: $remoteUrl");
            return null;
        }

        $filename = $meditationId . '_' . time() . '.jpg';
        $filePath = $this->imageDir . $filename;

        if (file_put_contents($filePath, $imageBytes) === false) {
            error_log("[GeminiImageService] Не удалось сохранить: $filePath");
            return null;
        }

        return $this->imageUrlBase . $filename;
    }
}
