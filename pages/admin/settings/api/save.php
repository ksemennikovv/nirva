<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

// Допустимые ключи и их типы
$schema = [
    'supervisor_mode'                  => 'bool',
    'subscription_required'            => 'bool',
    'analysis_min_interval_days'       => 'int',
    'burn_period_days'                 => 'int',
    'burn_show_min_analyses'           => 'int',
    'dashboard_diary_days_threshold'   => 'int',
    'dashboard_diary_daily_show_limit' => 'int',
    'diary_free_entries_limit'         => 'int',
    'meditation_auto_generate'         => 'bool',
    'meditation_generate_count'        => 'int',
    'meditation_free_window_days'      => 'int',
    'meditation_set_discount_pct'      => 'int',
    'referral_bonus_months'            => 'int',
    // Генерация изображений
    'image_provider_personal'          => 'string',
    'image_provider_general'           => 'string',
    'flux_model_personal'              => 'string',
    'flux_model_general'               => 'string',
    'imagen_model_personal'            => 'string',
    'imagen_model_general'             => 'string',
    'imagen_aspect_personal'           => 'string',
    'imagen_aspect_general'            => 'string',
    'imagen_resolution_personal'       => 'string',
    'imagen_resolution_general'        => 'string',
    // legacy single keys (для обратной совместимости)
    'flux_model'                       => 'string',
    'imagen_model'                     => 'string',
    'imagen_aspect_ratio'              => 'string',
    'imagen_resolution'                => 'string',
    'image_style_flux'                 => 'string',
    'image_style_imagen'               => 'string',
    'image_prompt_personal'            => 'string',
    'image_prompt_general'             => 'string',
    // ИИ-ассистент
    'ai_provider'                      => 'string',
    'ai_model'                         => 'string',
];

$db   = Database::getConnection();
$stmt = $db->prepare(
    'INSERT INTO app_settings (key_name, value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE value = VALUES(value)'
);

foreach ($schema as $key => $type) {
    if ($type === 'bool') {
        $val = isset($_POST[$key]) && $_POST[$key] == '1' ? '1' : '0';
    } elseif ($type === 'string') {
        $val = trim($_POST[$key] ?? '');
    } else {
        $val = (string)max(0, (int)($_POST[$key] ?? 0));
    }
    $stmt->execute([$key, $val]);
}

header('Location: /admin/settings/?saved=1');
