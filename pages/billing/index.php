<?php
session_start();
$root = dirname(__DIR__, 2);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/src/middleware/auth.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/SubscriptionRepository.php';
require_once $root . '/src/repositories/AnalysisRepository.php';
require_once $root . '/src/repositories/ReferralRepository.php';
require_once $root . '/src/services/Payment/PaymentService.php';

$subRepo      = new SubscriptionRepository();
$analysisRepo = new AnalysisRepository();
$refRepo      = new ReferralRepository();

$subscription  = $subRepo->getActive($currentUserId);
$prices        = PaymentService::getPrices();

// ── Остаток разборов ──────────────────────────────────────────────────────
$remaining = 0;
$burnDate  = null;

if ($subscription) {
    $remaining = max(0, $subscription['analyses_per_month'] - $subscription['analyses_used']);
    $burnDate  = BusinessConfig::getBurnDate($subscription);
}

// ── История трансформации (последние 3 завершённых разбора) ────────────────
$completedSessions = $analysisRepo->getUserSessions($currentUserId, 'completed');
$recentSessions    = array_slice($completedSessions, 0, 3);

$monthsRu = ['', 'января','февраля','марта','апреля','мая','июня',
             'июля','августа','сентября','октября','ноября','декабря'];

// ── Реферальная ссылка ────────────────────────────────────────────────────
$refCode = $refRepo->findOrCreateCode($currentUserId);
$refLink = 'https://' . $_SERVER['HTTP_HOST'] . '/?ref=' . $refCode;

// ── Лейблы ───────────────────────────────────────────────────────────────
$planLabels  = BusinessConfig::PLAN_LABELS;
$planDetails = BusinessConfig::PLAN_DETAILS;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Биллинг — Nirva</title>
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/pages/billing/billing.css">
</head>
<body>

<header class="app-header">
    <a href="/dashboard/" class="app-header__back">← обратно</a>
    <span class="app-header__logo">Nirva</span>
    <a href="/logout/" class="app-header__logout">Выйти</a>
</header>

<main class="billing-page">

    <h1 class="billing-page__title">Биллинг</h1>

    <!-- ── 1. Текущая подписка ───────────────────────────────────────────── -->
    <section class="billing-card">
        <div class="billing-card__label">Ваш формат сопровождения:</div>
        <?php if ($subscription): ?>
            <div class="billing-card__plan"><?php echo htmlspecialchars($planLabels[$subscription['plan']] ?? $subscription['plan']); ?></div>
            <div class="billing-card__meta"><?php echo htmlspecialchars($planDetails[$subscription['plan']] ?? ''); ?></div>
            <div class="billing-card__meta">Активен до <?php echo date('d.m.Y', strtotime($subscription['expires_at'])); ?></div>
            <button class="billing-card__change" id="btn-change-plan" type="button">изменить формат</button>
        <?php else: ?>
            <div class="billing-card__plan">Нет подписки</div>
            <button class="billing-card__change" id="btn-change-plan" type="button">выбрать формат</button>
        <?php endif; ?>
    </section>

    <!-- ── 2. Доступные разборы ──────────────────────────────────────────── -->
    <?php if ($subscription): ?>
    <section class="billing-card">
        <div class="billing-card__label">Доступные разборы:</div>
        <div class="billing-card__count"><?php echo $remaining; ?> из <?php echo $subscription['analyses_per_month']; ?> разборов</div>
        <?php if ($burnDate): ?>
        <div class="billing-card__hint">Используй минимум 1 до <?php echo $burnDate; ?></div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- ── 3. Реферальный блок ───────────────────────────────────────────── -->
    <section class="billing-card billing-referral">
        <div class="billing-referral__title">Поделитесь с друзьями доступом к трансформациям</div>
        <div class="billing-referral__desc">За каждого человека, который начнет сопровождение, Вы получаете +1 месяц бесплатно</div>
        <div class="billing-referral__link-row">
            <div class="billing-referral__link" id="referral-link-text"><?php echo htmlspecialchars($refLink); ?></div>
            <button class="billing-referral__copy" id="btn-copy-ref" type="button">скопировать</button>
        </div>
    </section>

    <!-- ── 4. История трансформации ─────────────────────────────────────── -->
    <section class="billing-card">
        <div class="billing-history__header">
            <span class="billing-card__label">История трансформации:</span>
            <a href="/archive/" class="billing-history__all">Вся история</a>
        </div>
        <?php if (empty($recentSessions)): ?>
            <div class="billing-history__empty">Завершённых разборов пока нет</div>
        <?php else: ?>
            <div class="billing-history__list">
                <?php foreach ($recentSessions as $s):
                    $ts    = strtotime($s['completed_at'] ?? $s['created_at']);
                    $day   = (int)date('j', $ts);
                    $month = $monthsRu[(int)date('n', $ts)];
                    $topic = $s['topic'] ?? 'Разбор';
                ?>
                <div class="billing-history__item">
                    <span class="billing-history__date"><?php echo $day . ' ' . $month; ?></span>
                    — Разбор по подписке<br>
                    <span class="billing-history__topic"><?php echo htmlspecialchars($topic); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ── 5. CTA ────────────────────────────────────────────────────────── -->
    <a href="/dashboard/" class="billing-cta">Продолжить путь трансформаций</a>

</main>

<?php require_once $root . '/features/plan-modal/plan-modal.php'; ?>

<script src="/assets/js/main.js"></script>
<script src="/pages/billing/billing.js"></script>
</body>
</html>
