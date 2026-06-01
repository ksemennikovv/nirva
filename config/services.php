<?php
/**
 * config/services.php — API ключи внешних сервисов.
 * Подключать в точках где нужны конкретные сервисы.
 */

// ── Fal.ai (Flux Pro image generation) ───────────────────────────────────────
define('FAL_API_KEY', 'ca8f7c8d-a4df-4150-9251-bfcdf5d18914:14e71e310c5d7b678d004a309fcb5dad');
define('FAL_API_URL', 'https://fal.run/fal-ai/flux-pro/v1.1');

// ── NanoBanana (Google Imagen 3) ──────────────────────────────────────────────
define('NANOBANANA_API_KEY', '2cd547f8d791fcbcea7cbe9b986620d1');
// Base URL: https://api.nanobananaapi.ai/api/v1/nanobanana (захардкожен в GeminiImageService)

// ── Папка для сохранения картинок медитаций ───────────────────────────────────
define('MEDITATION_IMAGE_DIR',      dirname(__DIR__) . '/assets/images/meditations/');
define('MEDITATION_IMAGE_URL_BASE', '/assets/images/meditations/');
