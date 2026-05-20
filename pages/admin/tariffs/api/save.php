<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/repositories/AppSettingsRepository.php';

$settings = new AppSettingsRepository();
$plans    = ['start', 'basic', 'transformation'];
$periods  = ['monthly', '6months', '12months'];

foreach ($plans as $p) {
    foreach ($periods as $per) {
        $key = "price_{$p}_{$per}";
        $val = (int)($_POST[$key] ?? 0);
        if ($val > 0) $settings->set($key, (string)$val, "Цена плана $p на период $per");
    }
    $apm = (int)($_POST["analyses_per_month_{$p}"] ?? 0);
    if ($apm > 0) $settings->set("analyses_per_month_{$p}", (string)$apm, "Разборов в месяц для плана $p");
}

$interval = (int)($_POST['analysis_min_interval_days'] ?? 0);
$settings->set('analysis_min_interval_days', (string)$interval, 'Мин. интервал между разборами (дней)');

$burn = max(1, (int)($_POST['burn_period_days'] ?? 30));
$settings->set('burn_period_days', (string)$burn, 'Период сгорания слота разбора (дней)');

$diaryFree = max(0, (int)($_POST['diary_free_entries_limit'] ?? 10));
$settings->set('diary_free_entries_limit', (string)$diaryFree, 'Бесплатных записей дневника без подписки');

header('Location: /admin/tariffs/?saved=1');
