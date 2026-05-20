<?php
session_start();
$root = dirname(__DIR__, 2);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/middleware/auth.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/AnalysisRepository.php';

$repo = new AnalysisRepository();

$filter  = $_GET['filter'] ?? 'all';
$allowed = ['all', 'completed', 'unfinished'];
if (!in_array($filter, $allowed)) $filter = 'all';

if ($filter === 'completed') {
    $sessions = $repo->getUserSessions($currentUserId, 'completed');
} elseif ($filter === 'unfinished') {
    $all      = $repo->getUserSessions($currentUserId);
    $sessions = array_values(array_filter($all, fn($s) => !in_array($s['status'], ['completed', 'abandoned', 'closed'])));
} else {
    $sessions = $repo->getUserSessions($currentUserId);
}

$statusLabels = [
    'created'                => 'Не начат',
    'active'                 => 'Идёт разбор',
    'draft_started'          => 'Начат',
    'chat_in_progress'       => 'Идёт разбор',
    'analysis_completed'     => 'Разбор завершён',
    'practice_assigned'      => 'Назначена практика',
    'practice_completed'     => 'Практика выполнена',
    'reflection_in_progress' => 'Самоисследование',
    'completed'              => 'Завершён',
    'abandoned'              => 'Прерван',
    'closed'                 => 'Закрыт',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Архив разборов — Nirva</title>
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/pages/archive/archive.css">
</head>
<body>

<header class="app-header">
    <a href="/dashboard/" class="app-header__back">← Главная</a>
    <span class="app-header__logo">Nirva</span>
    <a href="/logout/" class="app-header__logout">Выйти</a>
</header>

<main class="archive-page">

    <h1 class="archive-page__title">Архив разборов</h1>

    <div class="archive-filters">
        <a href="/archive/" class="archive-filter <?php echo $filter === 'all' ? 'archive-filter--active' : ''; ?>">Все</a>
        <a href="/archive/?filter=completed" class="archive-filter <?php echo $filter === 'completed' ? 'archive-filter--active' : ''; ?>">Завершённые</a>
        <a href="/archive/?filter=unfinished" class="archive-filter <?php echo $filter === 'unfinished' ? 'archive-filter--active' : ''; ?>">Незавершённые</a>
    </div>

    <?php if (empty($sessions)): ?>
    <p class="archive-empty">Разборов пока нет.</p>
    <?php else: ?>
    <div class="archive-list">
        <?php foreach ($sessions as $s): ?>
        <a href="/analysis/<?php echo $s['id']; ?>/" class="archive-item">
            <div class="archive-item__topic">
                <?php echo htmlspecialchars($s['topic'] ?: 'Разбор без темы'); ?>
            </div>
            <div class="archive-item__meta">
                <span class="archive-item__status <?php echo $s['status'] === 'completed' ? 'status--done' : ''; ?>">
                    <?php echo $statusLabels[$s['status']] ?? $s['status']; ?>
                </span>
                <span class="archive-item__date">
                    <?php echo date('d.m.Y', strtotime($s['created_at'])); ?>
                </span>
            </div>
            <?php if ($s['analysis_summary']): ?>
            <p class="archive-item__preview">
                <?php echo htmlspecialchars(mb_substr($s['analysis_summary'], 0, 120)); ?>…
            </p>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

<script src="/assets/js/main.js"></script>
</body>
</html>
