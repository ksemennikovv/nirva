<?php
/**
 * pages/admin/_layout.php
 * Общий HTML-каркас админ-панели.
 * Переменные: $pageTitle (string), $activeNav (string — users/analyses/subscriptions/tariffs/meditations/diary/portrait)
 */
$pageTitle = $pageTitle ?? 'Админ';
$activeNav = $activeNav ?? '';

// Счётчик pending для badge
if (!isset($supervisorPending)) {
    require_once dirname(__DIR__, 2) . '/config/business.php';
    require_once dirname(__DIR__, 2) . '/src/services/Database/Database.php';
    require_once dirname(__DIR__, 2) . '/assets/php/helpers.php';
    $supervisorPending = BusinessConfig::isSupervisorMode()
        ? (int)Database::getConnection()->query("SELECT COUNT(*) FROM messages WHERE role='assistant' AND review_status='pending_review'")->fetchColumn()
        : 0;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle); ?> — Nirva Admin</title>
    <link rel="stylesheet" href="<?= asset_url('/pages/admin/admin.css') ?>">
</head>
<body>

<!-- Оверлей для мобильного сайдбара -->
<div class="adm-sidebar-overlay" id="adm-overlay"></div>

<aside class="adm-sidebar" id="adm-sidebar">
    <div class="adm-sidebar__logo">
        Nirva
        <span>Панель администратора</span>
    </div>

    <nav class="adm-nav">
        <a href="/admin/" class="<?php echo $activeNav === 'dashboard' ? 'active' : ''; ?>">
            📊 Дашборд
        </a>
        <div class="adm-nav__sep"></div>
        <a href="/admin/users/" class="<?php echo $activeNav === 'users' ? 'active' : ''; ?>">
            👥 Пользователи
        </a>
        <a href="/admin/analyses/" class="<?php echo $activeNav === 'analyses' ? 'active' : ''; ?>">
            📋 Разборы
        </a>
        <?php if ($supervisorPending > 0 || BusinessConfig::isSupervisorMode()): ?>
        <a href="/admin/analyses/supervise.php" class="<?php echo $activeNav === 'supervise' ? 'active' : ''; ?>" style="display:flex;justify-content:space-between;align-items:center">
            <span>🔧 Надзор</span>
            <?php if ($supervisorPending > 0): ?>
            <span style="background:var(--danger);color:#fff;border-radius:10px;padding:2px 7px;font-size:11px;font-weight:700"><?php echo $supervisorPending; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        <a href="/admin/subscriptions/" class="<?php echo $activeNav === 'subscriptions' ? 'active' : ''; ?>">
            💳 Подписки
        </a>
        <a href="/admin/tariffs/" class="<?php echo $activeNav === 'tariffs' ? 'active' : ''; ?>">
            🎯 Тарифы
        </a>
        <div class="adm-nav__sep"></div>
        <a href="/admin/meditations/" class="<?php echo $activeNav === 'meditations' ? 'active' : ''; ?>">
            🎧 Медитации
        </a>
        <a href="/admin/diary/" class="<?php echo $activeNav === 'diary' ? 'active' : ''; ?>">
            📔 Дневник
        </a>
        <a href="/admin/practices/" class="<?php echo $activeNav === 'practices' ? 'active' : ''; ?>">
            🏋️ Практики
        </a>
        <div class="adm-nav__sep"></div>
        <a href="/admin/settings/" class="<?php echo $activeNav === 'settings' ? 'active' : ''; ?>">
            ⚙️ Настройки
        </a>
    </nav>

    <div class="adm-sidebar__exit">
        <a href="/dashboard/">← Выйти из панели</a>
    </div>
</aside>


<main class="adm-main">
    <div class="adm-topbar">
        <button class="adm-burger" id="adm-burger" aria-label="Меню">
            <svg width="18" height="14" viewBox="0 0 18 14" fill="none">
                <rect width="18" height="2" rx="1" fill="currentColor"/>
                <rect y="6" width="18" height="2" rx="1" fill="currentColor"/>
                <rect y="12" width="12" height="2" rx="1" fill="currentColor"/>
            </svg>
        </button>
        <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
        <span style="font-size:12px;color:var(--muted)"><?php echo htmlspecialchars($adminUser['email'] ?? ''); ?></span>
    </div>
    <div class="adm-content">
