<?php
session_start();
$root = dirname(__DIR__, 2);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/assets/php/helpers.php';
require_once $root . '/src/middleware/auth.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/DiaryRepository.php';
require_once $root . '/src/repositories/SubscriptionRepository.php';
require_once $root . '/src/repositories/ProfileParameterRepository.php';

$diaryRepo   = new DiaryRepository();
$subRepo     = new SubscriptionRepository();
$profileRepo = new ProfileParameterRepository();

$subscription  = $subRepo->getActive($currentUserId);
$entryCount    = $diaryRepo->countUserEntries($currentUserId);
$freeLimit     = BusinessConfig::diaryFreeEntriesLimit();
$canWrite      = $subscription || $entryCount < $freeLimit;
$entries       = $diaryRepo->getUserEntries($currentUserId);
$memories      = $profileRepo->getTopMemories($currentUserId, 3);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Дневник — Nirva</title>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/main.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/pages/diary/diary.css') ?>">
</head>
<body>

<div class="phone phone--full">

<header class="app-header">
    <a href="/dashboard/" class="app-header__back">
        <svg width="7" height="12" viewBox="0 0 7 12" fill="none"><path d="M6 1L1 6l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Назад
    </a>
    <a href="/dashboard/" class="app-header__logo-orb" style="width:36px;height:36px;font-size:14px">N</a>
</header>

<main class="diary-page">

    <section class="diary-header">
        <h1>Дневник</h1>
        <p class="diary-header__desc">Записывайте мысли и события — AI поможет увидеть паттерны и поддержит между разборами.</p>
    </section>

    <!-- ── Блок написания записи ─────────────────────────────────────────── -->
    <?php if ($canWrite): ?>
    <section class="diary-compose">
        <p class="diary-compose__question">Что сегодня было важным для Вас? Опиши событие, мысль, эмоцию или телесное ощущение.</p>
        <div class="diary-compose__chips">
            <button class="diary-compose__chip" data-mode="vent">Просто выговориться</button>
            <button class="diary-compose__chip" data-mode="reflection">Провести мини-исследование эмоций</button>
        </div>
        <textarea id="diary-input" class="diary-compose__textarea"
                  placeholder="Хочу просто выговориться на тему сегодняшнего события..."></textarea>
        <div class="diary-compose__actions">
            <button class="btn-primary" id="btn-start-diary">Начать запись</button>
            <?php if (!$subscription): ?>
            <span class="diary-compose__limit">Бесплатных записей: <?php echo max(0, $freeLimit - $entryCount); ?> из <?php echo $freeLimit; ?></span>
            <?php endif; ?>
        </div>
    </section>
    <?php else: ?>
    <section class="diary-paywall">
        <p>Вы использовали все бесплатные записи. Оформите подписку для неограниченного дневника.</p>
        <a href="/billing/" class="btn-primary">Оформить подписку</a>
    </section>
    <?php endif; ?>

    <!-- ── AI заметил ────────────────────────────────────────────────────── -->
    <?php if (!empty($memories)): ?>
    <section class="diary-observations">
        <h2 class="diary-observations__title">Nirva AI заметил у вас</h2>
        <ul class="observations-list">
            <?php foreach ($memories as $m): ?>
            <li><?php echo htmlspecialchars($m['content']); ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <!-- ── История записей ───────────────────────────────────────────────── -->
    <?php if (!empty($entries)): ?>
    <section class="diary-history">
        <h2 class="diary-history__title">История</h2>

        <div class="diary-filters">
            <button class="diary-filter active" data-filter="all">Все</button>
            <button class="diary-filter" data-filter="completed">Завершённые</button>
            <button class="diary-filter" data-filter="incomplete">Незавершённые</button>
            <button class="diary-filter" data-filter="date">По дате</button>
        </div>

        <div class="diary-date-range" id="diary-date-range" hidden>
            <input type="date" id="diary-date-from" class="diary-date-input">
            <span class="diary-date-sep">—</span>
            <input type="date" id="diary-date-to" class="diary-date-input">
        </div>

        <div class="diary-entries" id="diary-entries-list">
            <?php foreach ($entries as $entry): ?>
            <div class="diary-entry"
                 data-entry-id="<?php echo $entry['id']; ?>"
                 data-completed="<?php echo $entry['summary'] ? '1' : '0'; ?>"
                 data-date="<?php echo date('Y-m-d', strtotime($entry['created_at'])); ?>">
                <div class="diary-entry__date"><?php echo date('d.m.Y, H:i', strtotime($entry['created_at'])); ?></div>
                <?php if ($entry['summary']): ?>
                <p class="diary-entry__summary"><?php echo nl2br(htmlspecialchars($entry['summary'])); ?></p>
                <?php else: ?>
                <p class="diary-entry__summary muted">Запись обрабатывается...</p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

</main>

</div><!-- /.phone -->

<!-- Chat Roller -->
<?php $chatRollerCloseMode = 'back'; require_once $root . '/features/chat-roller/chat-roller.php'; ?>

<script src="<?= asset_url('/assets/js/main.js') ?>"></script>
<script src="<?= asset_url('/pages/diary/diary.js') ?>"></script>
</body>
</html>
