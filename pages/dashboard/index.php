<?php
session_start();
$root = dirname(__DIR__, 2);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/src/middleware/auth.php';
require_once $root . '/src/middleware/subscription.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/AnalysisRepository.php';
require_once $root . '/src/repositories/MeditationRepository.php';
require_once $root . '/src/repositories/ProfileParameterRepository.php';
require_once $root . '/src/repositories/DiaryRepository.php';
require_once $root . '/src/services/Payment/PaymentService.php';

$analysisRepo = new AnalysisRepository();
$medRepo      = new MeditationRepository();
$profileRepo  = new ProfileParameterRepository();
$diaryRepo    = new DiaryRepository();

$lastCompleted     = $analysisRepo->getLastCompleted($currentUserId);
$heroState         = $subHeroState;
$remainingAnalyses = $subRemaining;

// Дата следующего разбора (для state 2)
$nextAnalysisDate = null;
if ($lastCompleted && BusinessConfig::ANALYSIS_MIN_INTERVAL_DAYS > 0) {
    $lastTs           = strtotime($lastCompleted['completed_at'] ?? $lastCompleted['updated_at']);
    $nextAnalysisDate = date('d.m', $lastTs + BusinessConfig::ANALYSIS_MIN_INTERVAL_DAYS * 86400);
}

// ── Дневник ───────────────────────────────────────────────────────────────────
$showDiaryBlock    = ($heroState !== 1);
$todayDiaryCount   = $diaryRepo->countTodayEntries($currentUserId);
$diaryWrittenToday = $todayDiaryCount >= BusinessConfig::DASHBOARD_DIARY_DAILY_SHOW_LIMIT;

// ── Незавершённые разборы ─────────────────────────────────────────────────────
$allSessions = $analysisRepo->getUserSessions($currentUserId);
$unfinished  = array_values(array_filter(
    $allSessions,
    fn($s) => !in_array($s['status'], ['completed', 'abandoned', 'closed'])
));

// ── Персональные медитации ────────────────────────────────────────────────────
$analysisMeditations = [];
if ($lastCompleted) {
    $analysisMeditations = $medRepo->getByAnalysis($lastCompleted['id'], $currentUserId);
    $analysisMeditations = array_filter($analysisMeditations, fn($m) => $m['generation_status'] === 'ready');
    $analysisMeditations = array_values($analysisMeditations);
}

// ── Сообщение и темы для разбора ─────────────────────────────────────────────
$suggestedTopics = [];
$personalMessage = '';
if ($heroState === 1) {
    $memories  = $profileRepo->getTopMemories($currentUserId, 5);
    $topParams = $profileRepo->getTopParameters($currentUserId, 6);
    foreach ($topParams as $p) {
        $vals = json_decode($p['value'] ?? '', true);
        if (is_array($vals)) {
            foreach ($vals as $v) { if (is_string($v) && $v !== '') $suggestedTopics[] = $v; }
        } elseif (!empty($p['value'])) {
            $suggestedTopics[] = $p['value'];
        }
    }
    foreach ($memories as $m) {
        if (count($suggestedTopics) >= 5) break;
        $content = $m['content'] ?? '';
        if (mb_strlen($content) < 60) $suggestedTopics[] = $content;
    }
    $suggestedTopics = array_slice(array_unique($suggestedTopics), 0, 4);
    if (!empty($memories) || !empty($topParams)) {
        $topicStr = ($lastCompleted && $lastCompleted['topic'])
            ? '«' . $lastCompleted['topic'] . '»'
            : 'последнего разбора';
        $personalMessage = $lastCompleted
            ? 'На основе темы ' . $topicStr . ' и вашего профиля я вижу несколько направлений, которые могут быть важны для вас сейчас. Выберите тему или опишите своё состояние — разберёмся вместе.'
            : 'Я изучил ваш профиль. Расскажите, что происходит сейчас — или выберите одну из тем ниже.';
    } else {
        $personalMessage = 'Расскажите, что вас беспокоит или что вы хотите понять про себя — я помогу разобраться.';
    }
}

// ── Персонализированное приветствие ──────────────────────────────────────────
$hour = (int)date('G');
if ($hour >= 5 && $hour < 12)       { $timeGreet = 'Доброе утро'; $timeEmoji = '🌅'; }
elseif ($hour >= 12 && $hour < 17)  { $timeGreet = 'Добрый день'; $timeEmoji = '☀️'; }
elseif ($hour >= 17 && $hour < 22)  { $timeGreet = 'Добрый вечер'; $timeEmoji = '🌆'; }
else                                 { $timeGreet = 'Доброй ночи'; $timeEmoji = '🌙'; }

// Подсказка дня зависит от контекста
if ($heroState === 1 && !empty($unfinished)) {
    $daySuggestion = 'У вас есть незавершённые разборы — самое время довести их до конца и получить полный результат.';
} elseif ($heroState === 1) {
    $daySuggestion = 'Сегодня хороший день для нового разбора. Опишите, что сейчас происходит — и мы разберёмся вместе.';
} elseif ($heroState === 2 && !$diaryWrittenToday) {
    $daySuggestion = 'Пока психика отдыхает после разбора, отличное время заполнить дневник — это помогает закрепить изменения.';
} elseif ($heroState === 2 && $diaryWrittenToday) {
    $daySuggestion = 'Вы уже сделали запись в дневнике — отлично. Дайте себе время интегрировать результаты разбора.';
} elseif ($heroState === 3) {
    $daySuggestion = 'Разборы этого месяца завершены. Ведите дневник и практикуйте медитации — это продолжает ваш путь трансформации.';
} elseif ($heroState === 4) {
    $daySuggestion = 'Оформите подписку — и начните свой первый разбор уже сегодня.';
} else {
    $daySuggestion = 'Рады видеть вас. Чем можем помочь сегодня?';
}

$prices = PaymentService::getPrices();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Главная — Nirva</title>
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/features/med-row/med-row.css">
    <link rel="stylesheet" href="/pages/dashboard/dashboard.css">
</head>
<body>

<header class="app-header">
    <span class="app-header__logo">Nirva</span>
    <nav class="app-header__nav">
        <a href="/diary/">Дневник</a>
        <a href="/meditations/">Медитации</a>
        <a href="/archive/">Архив</a>
        <a href="/billing/">Тариф</a>
        <a href="/logout/" class="app-header__logout">Выйти</a>
    </nav>
</header>

<main class="dashboard-page">

    <!-- DEBUG (временно) -->
    <?php if (isset($_GET['debug'])): ?>
    <div style="background:#fff3cd;padding:1rem;margin:1rem;border-radius:8px;font-size:.85rem;font-family:monospace">
        <strong>heroState=<?php echo $heroState; ?></strong><br>
        subscription: <?php echo $subscription ? "id={$subscription['id']} plan={$subscription['plan']} per_month={$subscription['analyses_per_month']} used={$subscription['analyses_used']} starts={$subscription['starts_at']} expires={$subscription['expires_at']}" : 'NULL'; ?><br>
        usedReal: <?php echo $usedReal ?? 'N/A'; ?><br>
        remainingAnalyses: <?php echo $remainingAnalyses ?? 'NULL'; ?><br>
        lastCompleted: <?php echo $lastCompleted ? "id={$lastCompleted['id']} status={$lastCompleted['status']}" : 'NULL'; ?>
    </div>
    <?php endif; ?>

    <!-- ══ ПРИВЕТСТВИЕ ═══════════════════════════════════════════════════════ -->
    <div class="db-greeting">
        <div class="db-greeting__line">
            <span class="db-greeting__emoji"><?php echo $timeEmoji; ?></span>
            <span class="db-greeting__hello"><?php echo $timeGreet; ?></span>
        </div>
        <p class="db-greeting__suggestion"><?php echo htmlspecialchars($daySuggestion); ?></p>
    </div>

    <!-- ══ ГЕРОЙ: три состояния ══════════════════════════════════════════════ -->

    <?php if ($heroState === 1): ?>
    <!-- ── Состояние 1: начать разбор ───────────────────────────────────── -->
    <div class="db-cloud db-cloud--analysis">
        <div class="db-cloud__tag">✦ Разбор</div>
        <p class="db-cloud__message"><?php echo htmlspecialchars($personalMessage); ?></p>

        <?php if (!empty($suggestedTopics)): ?>
        <div class="db-topics">
            <?php foreach ($suggestedTopics as $topic): ?>
            <button class="db-topic-chip" type="button"><?php echo htmlspecialchars($topic); ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="db-input-wrap">
            <textarea class="db-input-textarea" id="db-input"
                placeholder="Например: я чувствую тревогу, пустоту, нет сил, застряла..."></textarea>
            <div class="db-input-actions">
                <button class="db-input-voice" id="db-voice-btn" type="button">🎙 диктофон</button>
                <button class="db-input-send" id="db-send-btn" type="button">отправить</button>
            </div>
        </div>
        <button class="db-cta-btn" id="db-start-analysis" type="button">Начать новый разбор</button>
        <div class="db-privacy">🔒 Конфиденциально · никто не увидит запрос</div>
    </div>

    <?php elseif ($heroState === 2): ?>
    <!-- ── Состояние 2: режим отдыха ────────────────────────────────────── -->
    <div class="db-cloud db-cloud--rest">
        <div class="db-cloud__tag db-cloud__tag--rest">🌿 Отдых</div>
        <div class="db-rest-done-row">
            <div class="db-rest-done-check">✓</div>
            <span class="db-rest-done-label">Разбор завершён</span>
        </div>
        <div class="db-rest-icon">🧠</div>
        <h2 class="db-rest-title">Ваша психика сейчас отдыхает</h2>
        <p class="db-rest-text">
            Следующий разбор будет доступен <strong><?php echo $nextAnalysisDate; ?></strong>.
            Это время для интеграции — позвольте процессу идти своим путём.
        </p>
        <a href="/archive/" class="db-rest-link">Посмотреть прошлые разборы →</a>
    </div>

    <?php elseif ($heroState === 3): ?>
    <!-- ── Состояние 3: лимит исчерпан — даём попробовать, потом paywall ── -->
    <div class="db-cloud db-cloud--analysis" data-paywall="1">
        <div class="db-cloud__tag db-cloud__tag--limit">💳 Лимит исчерпан</div>
        <p class="db-cloud__message">Вы использовали все разборы этого месяца. Попробуйте демо-разбор — и оформите подписку чтобы продолжить.</p>
        <div class="db-input-wrap">
            <textarea class="db-input-textarea" id="db-input"
                placeholder="Опишите, что вас беспокоит..."></textarea>
            <div class="db-input-actions">
                <button class="db-input-voice" id="db-voice-btn" type="button">🎙 диктофон</button>
                <button class="db-input-send" id="db-send-btn" type="button">отправить</button>
            </div>
        </div>
        <div class="db-privacy">🔒 Конфиденциально · никто не увидит запрос</div>
    </div>

    <?php else: ?>
    <!-- ── Состояние 4: нет подписки — даём попробовать, потом paywall ──── -->
    <div class="db-cloud db-cloud--analysis" data-paywall="1">
        <div class="db-cloud__tag">✦ Разбор</div>
        <p class="db-cloud__message">Попробуйте первый разбор бесплатно — почувствуйте, как это работает, и оформите подписку чтобы получить полный результат.</p>
        <div class="db-input-wrap">
            <textarea class="db-input-textarea" id="db-input"
                placeholder="Например: я чувствую тревогу, пустоту, нет сил, застряла..."></textarea>
            <div class="db-input-actions">
                <button class="db-input-voice" id="db-voice-btn" type="button">🎙 диктофон</button>
                <button class="db-input-send" id="db-send-btn" type="button">отправить</button>
            </div>
        </div>
        <div class="db-privacy">🔒 Конфиденциально · никто не увидит запрос</div>
    </div>
    <?php endif; ?>

    <!-- ══ НЕЗАВЕРШЁННЫЕ РАЗБОРЫ (только когда доступен новый разбор) ════════ -->
    <?php if ($heroState === 1 && !empty($unfinished)): ?>
    <div class="db-cloud db-cloud--unfinished">
        <div class="db-cloud__label">Незавершённые разборы</div>
        <p class="db-unfinished-hint">Если собрались с силами и готовы взглянуть на поднятую там ситуацию — самое время вернуться и завершить.</p>
        <div class="db-unfinished-list">
            <?php foreach (array_slice($unfinished, 0, 3) as $s): ?>
            <a href="/analysis/<?php echo $s['id']; ?>/" class="db-unfinished-card">
                <div class="db-unfinished-card__dot"></div>
                <div class="db-unfinished-card__text">
                    <?php echo htmlspecialchars($s['topic'] ?: 'Разбор без темы'); ?>
                </div>
                <span class="db-unfinished-card__arrow">→</span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ ДНЕВНИК (состояния 2 и 3) ════════════════════════════════════════ -->
    <?php if ($showDiaryBlock): ?>
    <div class="db-cloud db-cloud--diary <?php echo $diaryWrittenToday ? 'db-cloud--diary-done' : ''; ?>">
        <?php if ($diaryWrittenToday): ?>
        <div class="db-diary-done">
            <div class="db-diary-done__check">✓</div>
            <div class="db-diary-done__body">
                <div class="db-diary-done__text">Сегодня вы уже сделали запись в дневнике — вы молодец. Продолжайте дневник завтра, чтобы Nirva глубже изучал ваш психоэмоциональный портрет. Если сегодня произошло что-то важное или вы испытали сильную эмоцию — можете записать и сейчас.</div>
                <a href="/diary/" class="db-diary-done__write-again">Записать ещё →</a>
                <a href="/diary/" class="db-diary-done__link">Открыть дневник →</a>
            </div>
        </div>
        <?php else: ?>
        <div class="db-diary-head">
            <span class="db-diary-head__icon">📖</span>
            <div>
                <div class="db-diary-head__title">Дневник</div>
                <div class="db-diary-head__sub">Зафиксируйте, что происходит сейчас — это часть процесса</div>
            </div>
        </div>
        <div class="db-diary-input-wrap">
            <textarea class="db-diary-textarea" id="db-diary-input"
                placeholder="Что сегодня было важным? Как вы себя чувствуете?"></textarea>
            <button class="db-diary-send" id="db-diary-send" type="button">Записать</button>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ══ ПЕРСОНАЛЬНЫЕ МЕДИТАЦИИ ════════════════════════════════════════════ -->
    <?php
    // Показываем блок если есть готовые медитации ИЛИ если последний разбор завершён (идёт генерация)
    $hasPendingMeditations = $lastCompleted && empty($analysisMeditations);
    ?>
    <?php if (!empty($analysisMeditations) || $hasPendingMeditations): ?>
    <div class="db-cloud db-cloud--meditations"
         <?php if ($lastCompleted): ?>data-last-analysis-id="<?php echo (int)$lastCompleted['id']; ?>"<?php endif; ?>
         <?php if ($hasPendingMeditations): ?>data-med-pending="1"<?php endif; ?>>
        <?php if (!empty($analysisMeditations)): ?>
        <?php
        $medRowItems   = $analysisMeditations;
        $medRowSlug    = 'analysis_' . ($lastCompleted['id'] ?? 'last');
        $medRowTitle   = 'Медитации для вас';
        $medRowSub     = 'По теме «' . ($lastCompleted['topic'] ?? 'разбора') . '»';
        $medRowMoreUrl = '/meditations/?filter=personal';
        require $root . '/features/med-row/med-row.php';
        ?>
        <?php else: ?>
        <div id="db-med-placeholder">
            <p class="muted" style="padding:12px 0">Персональные медитации формируются...</p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ══ БЫСТРЫЕ ССЫЛКИ ════════════════════════════════════════════════════ -->
    <section class="dashboard-links">
        <a href="/diary/" class="dashboard-link-card">
            <span class="dashboard-link-card__icon">📖</span>
            <span>Дневник</span>
        </a>
        <a href="/meditations/" class="dashboard-link-card">
            <span class="dashboard-link-card__icon">🎵</span>
            <span>Медитации</span>
        </a>
        <a href="/archive/" class="dashboard-link-card">
            <span class="dashboard-link-card__icon">📋</span>
            <span>Архив</span>
        </a>
        <a href="/billing/" class="dashboard-link-card">
            <span class="dashboard-link-card__icon">💳</span>
            <span>Тариф</span>
        </a>
    </section>

</main>

<?php require_once $root . '/features/med-modal/med-modal.php'; ?>
<?php require_once $root . '/features/plan-modal/plan-modal.php'; ?>
<?php $chatRollerCloseMode = 'stay'; require_once $root . '/features/chat-roller/chat-roller.php'; ?>
<?php require_once $root . '/features/med-cart/med-cart.php'; ?>

<script src="/assets/js/main.js"></script>
<script src="/pages/dashboard/dashboard.js"></script>
</body>
</html>
