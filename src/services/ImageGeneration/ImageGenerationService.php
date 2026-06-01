<?php

require_once __DIR__ . '/FluxImageService.php';
require_once __DIR__ . '/GeminiImageService.php';

/**
 * Диспетчер генерации изображений для медитаций.
 * Выбирает провайдера, строит промт, вызывает нужный сервис.
 */
class ImageGenerationService
{
    private const DEFAULT_PROMPT_PERSONAL =
        'sacred feminine energy, {title}, spiritual healing meditation, ' .
        '{description}, soft divine golden light, ethereal atmosphere, ' .
        'dreamlike sacred space, woman silhouette, no text, no watermark, ' .
        'vertical portrait, cinematic quality, {style}';

    private const DEFAULT_PROMPT_GENERAL =
        'meditation artwork, {title}, {category} theme, ' .
        '{description}, soft pastel light, peaceful sacred space, ' .
        'spiritual atmosphere, abstract divine, no text, no watermark, ' .
        'square format, high quality, {style}';

    private const STYLE_FLUX   = 'photorealistic, sharp details, 8K resolution';
    private const STYLE_IMAGEN = 'painterly, vivid colors, artistic quality';

    /**
     * Основной метод — строит промт и вызывает нужный провайдер.
     *
     * @param string $provider   'flux' | 'imagen' | 'none'
     * @param array  $meditation Поля из таблицы meditations
     * @param string $category   Название категории (если есть)
     * @return string|null       URL сохранённого изображения или null
     */
    public static function generate(
        string $provider,
        array  $meditation,
        string $category = ''
    ): ?string {
        if ($provider === 'none' || !$provider) return null;

        $prompt = self::buildPrompt($provider, $meditation, $category);

        try {
            $isPersonal = ($meditation['type'] ?? 'general') === 'personal';
            $suffix     = $isPersonal ? '_personal' : '_general';

            if ($provider === 'flux') {
                $fluxModel = class_exists('BusinessConfig')
                    ? (BusinessConfig::setting('flux_model' . $suffix, '') ?: BusinessConfig::setting('flux_model', 'fal-ai/flux-pro/v1.1'))
                    : 'fal-ai/flux-pro/v1.1';
                return (new FluxImageService($fluxModel))->generate($prompt, (int)$meditation['id']);
            }
            if ($provider === 'imagen') {
                $model  = class_exists('BusinessConfig')
                    ? (BusinessConfig::setting('imagen_model' . $suffix, '') ?: BusinessConfig::setting('imagen_model', 'nano-banana-2'))
                    : 'nano-banana-2';
                $aspect = class_exists('BusinessConfig')
                    ? (BusinessConfig::setting('imagen_aspect' . $suffix, '') ?: BusinessConfig::setting('imagen_aspect_ratio', '9:16'))
                    : '9:16';
                $res    = class_exists('BusinessConfig')
                    ? (BusinessConfig::setting('imagen_resolution' . $suffix, '') ?: BusinessConfig::setting('imagen_resolution', '1K'))
                    : '1K';
                return (new GeminiImageService($model, $aspect, $res))->generate($prompt, (int)$meditation['id']);
            }
            return null;
        } catch (\Throwable $e) {
            error_log("[ImageGenerationService] Ошибка провайдера '$provider': " . $e->getMessage());
            return null;
        }
    }

    private static function buildPrompt(string $provider, array $meditation, string $category): string
    {
        $isPersonal = ($meditation['type'] ?? 'general') === 'personal';

        // Загружаем шаблон из DB (через BusinessConfig если доступен, иначе дефолт)
        if (class_exists('BusinessConfig')) {
            $templateKey = $isPersonal ? 'image_prompt_personal' : 'image_prompt_general';
            $template    = BusinessConfig::setting($templateKey,
                $isPersonal ? self::DEFAULT_PROMPT_PERSONAL : self::DEFAULT_PROMPT_GENERAL
            );
            $styleKey    = 'image_style_' . $provider;
            $style       = BusinessConfig::setting($styleKey,
                $provider === 'flux' ? self::STYLE_FLUX : self::STYLE_IMAGEN
            );
        } else {
            $template = $isPersonal ? self::DEFAULT_PROMPT_PERSONAL : self::DEFAULT_PROMPT_GENERAL;
            $style    = $provider === 'flux' ? self::STYLE_FLUX : self::STYLE_IMAGEN;
        }

        $description = self::extractKeywords($meditation['description'] ?? $meditation['personal_context'] ?? '');

        return strtr($template, [
            '{title}'       => $meditation['title']       ?? '',
            '{description}' => $description,
            '{topic}'       => $meditation['topic']       ?? '',
            '{category}'    => $category,
            '{style}'       => $style,
        ]);
    }

    /** Берёт первые ~100 символов описания как ключевые слова для промта */
    private static function extractKeywords(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', trim($text));
        return mb_substr($text, 0, 120);
    }
}
