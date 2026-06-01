<?php
session_start();
$root = dirname(__DIR__, 2);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/assets/php/helpers.php';
require_once $root . '/src/middleware/auth.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/subscription.php';
require_once $root . '/src/repositories/AnalysisRepository.php';
require_once $root . '/src/repositories/MeditationRepository.php';

$analysisId = (int)($_GET['id'] ?? 0);
if (!$analysisId) { header('Location: /dashboard/'); exit; }

$analysisRepo = new AnalysisRepository();
$analysis     = $analysisRepo->getSession($analysisId);

if (!$analysis || (int)$analysis['user_id'] !== $currentUserId) {
    header('Location: /dashboard/');
    exit;
}

$medRepo             = new MeditationRepository();
$allMeditations      = $medRepo->getByAnalysis($analysisId, $currentUserId);
$analysisMeditations = array_filter($allMeditations, fn($m) => $m['generation_status'] === 'ready');
$analysisMeditations = array_values($analysisMeditations);

$isFirstAnalysis = $analysisRepo->countCompleted($currentUserId) <= 1;

$status            = $analysis['status'];
$chatNotDone       = in_array($status, ['created', 'active', 'chat_in_progress']);
$isNewSession      = false;
$practiceCompleted = in_array($status, ['practice_completed','reflection_in_progress','completed']);
$reflectionDone    = $status === 'completed';

// Paywall: использует данные из subscription.php middleware
$paywallMode = $chatNotDone && $subPaywallActive;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title><?php echo htmlspecialchars($analysis['topic'] ?? 'Разбор'); ?> — Nirva</title>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/main.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/pages/analysis/analysis.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/features/med-row/med-row.css') ?>">
</head>
<body>

<div class="phone phone--full">

<header class="app-header">
    <a href="/archive/" class="app-header__back">
        <svg width="7" height="12" viewBox="0 0 7 12" fill="none"><path d="M6 1L1 6l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Архив
    </a>
    <a href="/dashboard/" class="app-header__logo-orb" style="width:36px;height:36px;font-size:14px">N</a>
</header>

<main class="analysis-page" data-analysis-id="<?php echo $analysisId; ?>">

    <!-- ── Мета-строка: тема + дата ─────────────────────────────────────────── -->
    <div class="analysis-meta">
        <span class="analysis-meta__topic" id="analysis-page-topic">
            <?php echo $analysis['topic'] ? htmlspecialchars($analysis['topic']) : '<em>Тема ещё не определена</em>'; ?>
        </span>
        <span class="analysis-meta__date">
            <?php echo date('d.m.Y', strtotime($analysis['created_at'])); ?>
        </span>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!-- Разбор ЕЩЁ НЕ ЗАВЕРШЁН: только кнопка входа в чат, больше ничего    -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <?php if ($chatNotDone): ?>

    <section class="analysis-block analysis-block--highlight" id="block-open-chat">
        <?php if ($isNewSession): ?>
            <p>Разбор готов к запуску. Нажмите кнопку ниже — и начнём.</p>
        <?php else: ?>
            <p>Разбор в процессе. Продолжите с того места, где остановились.</p>
        <?php endif; ?>
        <button class="btn-primary" id="btn-open-chat" type="button" style="max-width:260px;">
            <?php echo $isNewSession ? 'Начать разбор →' : 'Продолжить разбор →'; ?>
        </button>
    </section>

    <?php else: ?>
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!-- Разбор ЗАВЕРШЁН: показываем все блоки                                 -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->

    <!-- ── 1. Блок разбора (сворачиваемый) + кнопка диалога ────────────────── -->
    <section class="analysis-block" id="block-summary" data-collapsed="<?php echo $practiceCompleted ? 'true' : 'false'; ?>">
        <div class="analysis-block__header">
            <h2>Разбор</h2>
            <button class="analysis-block__toggle" type="button"><?php echo $practiceCompleted ? 'Развернуть' : 'Свернуть'; ?></button>
        </div>
        <div class="analysis-block__body">
            <?php if (!empty($analysis['analysis_summary'])): ?>
                <p class="analysis-summary__text"><?php echo nl2br(htmlspecialchars($analysis['analysis_summary'])); ?></p>
            <?php endif; ?>
            <button class="btn-secondary" id="btn-show-chat" type="button">Посмотреть весь диалог</button>
        </div>
    </section>

    <!-- ── 2. Онбординг-видео (только первый разбор, до завершения) ─────────── -->
    <?php if ($isFirstAnalysis && !$reflectionDone): ?>
    <section class="analysis-block" id="block-onboarding">
        <div class="analysis-block__header"><h2>Как это работает</h2></div>
        <div class="analysis-block__body">
            <div class="analysis-onboarding-video">
                <video controls playsinline preload="metadata"
                       src="/assets/video/onboarding.mp4"
                       poster="/assets/img/onboarding-poster.jpg">
                </video>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ── 3. Персональное задание ───────────────────────────────────────── -->
    <section class="analysis-block" id="block-task" data-collapsed="<?php echo $practiceCompleted ? 'true' : 'false'; ?>">
        <div class="analysis-block__header">
            <h2>Ваше задание</h2>
            <button class="analysis-block__toggle" type="button"><?php echo $practiceCompleted ? 'Развернуть' : 'Свернуть'; ?></button>
        </div>
        <div class="analysis-block__body">
            <?php if (!empty($analysis['personal_task'])): ?>
                <p><?php echo nl2br(htmlspecialchars($analysis['personal_task'])); ?></p>
            <?php else: ?>
                <p class="muted">Задание появится после завершения разбора.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- ── 4. Практика ───────────────────────────────────────────────────── -->
    <section class="analysis-block" id="block-practice" data-collapsed="<?php echo $practiceCompleted ? 'true' : 'false'; ?>">
        <div class="analysis-block__header">
            <h2>Практика: <?php echo htmlspecialchars($analysis['selected_practice'] ?? ''); ?></h2>
            <button class="analysis-block__toggle" type="button"><?php echo $practiceCompleted ? 'Развернуть' : 'Свернуть'; ?></button>
        </div>
        <div class="analysis-block__body">
            <div class="practice-video">
                <video controls controlsList="nodownload" playsinline
                       oncontextmenu="return false;"
                       poster="/assets/video/practices/<?php echo urlencode($analysis['selected_practice'] ?? 'default'); ?>-poster.jpg">
                    <source src="/assets/video/practices/<?php echo urlencode($analysis['selected_practice'] ?? 'default'); ?>.mp4" type="video/mp4">
                </video>
            </div>
            <?php if (!$practiceCompleted): ?>
            <button class="btn-primary" id="btn-practice-done" type="button"
                    data-analysis-id="<?php echo $analysisId; ?>">
                Практика выполнена ✓
            </button>
            <?php endif; ?>
        </div>
    </section>

    <!-- ── 5. CTA самоисследования (после практики, до reflection) ────────── -->
    <?php if ($practiceCompleted && !$reflectionDone): ?>
    <section class="analysis-block analysis-block--highlight" id="block-reflection-cta">
        <p>Вы молодец. Чтобы закрепить результат и развить эмоциональный интеллект — пройдите самоисследование.</p>
        <button class="btn-primary" id="btn-start-reflection" type="button"
                data-analysis-id="<?php echo $analysisId; ?>">
            Начать самоисследование
        </button>
    </section>
    <?php endif; ?>

    <!-- ── 6. Итоги (после reflection) ───────────────────────────────────── -->
    <?php if ($reflectionDone): ?>
    <section class="analysis-block" id="block-results">
        <div class="analysis-block__header"><h2>Итоги</h2></div>
        <div class="analysis-block__body">
            <?php if ($analysis['reflection_summary']): ?>
                <h3>Самоисследование</h3>
                <p><?php echo nl2br(htmlspecialchars($analysis['reflection_summary'])); ?></p>
            <?php endif; ?>
            <?php if ($analysis['final_recommendations']): ?>
                <h3>Рекомендации</h3>
                <p><?php echo nl2br(htmlspecialchars($analysis['final_recommendations'])); ?></p>
            <?php endif; ?>
        </div>
    </section>

    <!-- ── 7. Персональные медитации ─────────────────────────────────────── -->
    <section class="analysis-block" id="block-meditations">
        <div class="analysis-block__header"><h2>Персональные медитации</h2></div>
        <div class="analysis-block__body">
            <?php if (empty($analysisMeditations)): ?>
                <p class="muted">Медитации формируются на основе вашего разбора. Загляните позже.</p>
            <?php else: ?>
                <?php
                $medRowItems   = $analysisMeditations;
                $medRowSlug    = 'analysis_' . $analysisId;
                $medRowTitle   = 'Медитации для вас';
                $medRowSub     = 'Создано специально по теме этого разбора';
                $medRowMoreUrl = '/meditations/?filter=personal';
                require $root . '/features/med-row/med-row.php';
                ?>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; // $reflectionDone ?>

    <?php endif; // $chatNotDone else ?>

</main>

<?php if (!empty($analysisMeditations)): require_once $root . '/features/med-modal/med-modal.php'; endif; ?>
<?php require_once $root . '/features/med-cart/med-cart.php'; ?>

<!-- Chat Roller -->
<?php
$_SESSION['analysis_session_id'] = $analysisId;
$_SESSION['analysis_topic']      = $analysis['topic'] ?? '';
$chatRollerCloseMode = 'back';
require_once $root . '/features/chat-roller/chat-roller.php';
?>

<script src="<?= asset_url('/assets/js/main.js') ?>"></script>
<script>const ANALYSIS_PAYWALL = <?php echo $paywallMode ? 'true' : 'false'; ?>;</script>
<script src="<?= asset_url('/pages/analysis/analysis.js') ?>"></script>
<?php
require_once $root . '/src/services/Payment/PaymentService.php';
$prices = PaymentService::getPrices();
require_once $root . '/features/plan-modal/plan-modal.php';
require_once $root . '/features/med-player/med-player.php';
?>

</div><!-- /.phone -->
</body>
</html>
