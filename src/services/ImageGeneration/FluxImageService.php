<?php

/**
 * Генерация изображений через Fal.ai
 * Поддерживает все Flux-совместимые модели на fal.run
 */
class FluxImageService
{
    // Модели и их endpoint slugs на fal.run
    public const MODELS = [
        'fal-ai/flux-pro/v1.1'              => 'Flux Pro 1.1 — $0.04/img ⭐⭐⭐⭐⭐',
        'fal-ai/flux-pro/v1.1-ultra'        => 'Flux Pro 1.1 Ultra — $0.06/img (4x разрешение)',
        'fal-ai/flux/dev'                   => 'Flux Dev — ~$0.025/img ⭐⭐⭐⭐',
        'fal-ai/flux/schnell'               => 'Flux Schnell — ~$0.003/img ⭐⭐⭐ (быстрый)',
        'fal-ai/flux-kontext-pro'           => 'Flux Kontext Pro — $0.04/img (редактирование)',
        'fal-ai/ideogram/v3'                => 'Ideogram V3 — ~$0.08/img (текст в картинке)',
        'fal-ai/recraft-v3'                 => 'Recraft V3 — ~$0.04/img (дизайн)',
    ];

    // Размеры по умолчанию для каждой модели
    private const MODEL_SIZES = [
        'fal-ai/flux-pro/v1.1'         => ['image_size' => 'portrait_4_3'],
        'fal-ai/flux-pro/v1.1-ultra'   => ['aspect_ratio' => '4:5'],
        'fal-ai/flux/dev'              => ['image_size' => 'portrait_4_3'],
        'fal-ai/flux/schnell'          => ['image_size' => 'portrait_4_3'],
        'fal-ai/flux-kontext-pro'      => ['image_size' => 'portrait_4_3'],
        'fal-ai/ideogram/v3'           => ['aspect_ratio' => '2:3', 'resolution' => 'RESOLUTION_1024'],
        'fal-ai/recraft-v3'            => ['image_size' => 'portrait_4_3'],
    ];

    private string $apiKey;
    private string $modelSlug;
    private string $imageDir;
    private string $imageUrlBase;

    public function __construct(string $modelSlug = '')
    {
        $this->apiKey       = defined('FAL_API_KEY')               ? FAL_API_KEY               : '';
        $this->imageDir     = defined('MEDITATION_IMAGE_DIR')      ? MEDITATION_IMAGE_DIR      : dirname(__DIR__, 3) . '/assets/images/meditations/';
        $this->imageUrlBase = defined('MEDITATION_IMAGE_URL_BASE') ? MEDITATION_IMAGE_URL_BASE : '/assets/images/meditations/';

        if (!$modelSlug && class_exists('BusinessConfig')) {
            $modelSlug = BusinessConfig::setting('flux_model', 'fal-ai/flux-pro/v1.1');
        }
        $this->modelSlug = $modelSlug ?: 'fal-ai/flux-pro/v1.1';
    }

    public function generate(string $prompt, int $meditationId): ?string
    {
        if (!$this->apiKey) {
            error_log('[FluxImageService] FAL_API_KEY не задан');
            return null;
        }

        $endpoint = 'https://fal.run/' . $this->modelSlug;
        $sizeParams = self::MODEL_SIZES[$this->modelSlug] ?? ['image_size' => 'portrait_4_3'];

        $payload = json_encode(array_merge([
            'prompt'                => $prompt,
            'num_images'            => 1,
            'enable_safety_checker' => false,
            'num_inference_steps'   => 28,
        ], $sizeParams));

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Key ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) { error_log("[FluxImageService] cURL: $curlError"); return null; }
        if ($httpCode !== 200) { error_log("[FluxImageService] HTTP $httpCode: $response"); return null; }

        $data     = json_decode($response, true);
        $imageUrl = $data['images'][0]['url'] ?? null;

        if (!$imageUrl) { error_log("[FluxImageService] Нет URL: $response"); return null; }

        return $this->downloadAndSave($imageUrl, $meditationId);
    }

    private function downloadAndSave(string $remoteUrl, int $meditationId): ?string
    {
        if (!is_dir($this->imageDir)) mkdir($this->imageDir, 0755, true);

        $imageBytes = file_get_contents($remoteUrl);
        if ($imageBytes === false || strlen($imageBytes) < 1000) {
            error_log("[FluxImageService] Не удалось скачать: $remoteUrl");
            return null;
        }

        $filename = $meditationId . '_' . time() . '.jpg';
        $filePath = $this->imageDir . $filename;

        if (file_put_contents($filePath, $imageBytes) === false) {
            error_log("[FluxImageService] Не удалось сохранить: $filePath");
            return null;
        }

        return $this->imageUrlBase . $filename;
    }
}
