<?php
/**
 * src/middleware/subscription.php — Централизованная проверка подписки.
 *
 * Подключать после auth.php (требует $currentUserId).
 * После подключения доступны переменные:
 *
 *   $subscription      — активная подписка (array) или null
 *   $subUsedReal       — реальное количество начатых разборов в текущем периоде
 *   $subRemaining      — сколько разборов осталось (0 если лимит исчерпан)
 *   $subHeroState      — состояние блока разборов на дашборде (1–4):
 *                          1 = можно начать разбор
 *                          2 = режим отдыха (интервал не прошёл)
 *                          3 = лимит исчерпан (подписка есть, разборы кончились)
 *                          4 = нет активной подписки
 *   $subPaywallActive  — bool, нужно ли показывать paywall (state 3 или 4)
 *   $subBurnDate       — дата сгорания следующего слота ('dd.mm.YYYY') или null
 */
if (!isset($currentUserId)) {
    throw new RuntimeException('subscription.php requires auth.php to be loaded first');
}

require_once __DIR__ . '/../repositories/SubscriptionRepository.php';
require_once __DIR__ . '/../services/Database/Database.php';
require_once __DIR__ . '/../../config/business.php';

$_subRepo     = new SubscriptionRepository();
$subscription = $_subRepo->getActive($currentUserId);

// ── Реальный счётчик разборов из БД ──────────────────────────────────────────
$subUsedReal  = 0;
$subRemaining = null; // null = без ограничений (нет подписки)

if ($subscription) {
    $_db        = Database::getConnection();
    $_countStmt = $_db->prepare(
        'SELECT COUNT(*) FROM analysis_sessions
         WHERE user_id = ?
           AND status NOT IN ("abandoned", "closed")
           AND created_at >= ?
           AND created_at <= ?'
    );
    $_countStmt->execute([
        $currentUserId,
        $subscription['starts_at'],
        $subscription['expires_at'],
    ]);
    $subUsedReal = (int)$_countStmt->fetchColumn();

    // Синхронизируем кэш-счётчик если расходится
    if ($subUsedReal !== (int)$subscription['analyses_used']) {
        $_db->prepare('UPDATE subscriptions SET analyses_used = ? WHERE id = ?')
            ->execute([$subUsedReal, $subscription['id']]);
        $subscription['analyses_used'] = $subUsedReal;
    }

    $subRemaining = max(0, (int)$subscription['analyses_per_month'] - $subUsedReal);
}

// ── Hero state ────────────────────────────────────────────────────────────────
if (!BusinessConfig::SUBSCRIPTION_REQUIRED) {
    // Режим без ограничений — все пользователи могут делать разборы
    $subHeroState = 1;
    $subRemaining = 999;
} elseif (!$subscription) {
    $subHeroState = 4;
} elseif ($subRemaining <= 0) {
    $subHeroState = 3;
} else {
    // Проверяем минимальный интервал от последнего разбора
    $_lastStmt = Database::getConnection()->prepare(
        'SELECT completed_at, updated_at FROM analysis_sessions
         WHERE user_id = ? AND status = "completed"
         ORDER BY completed_at DESC LIMIT 1'
    );
    $_lastStmt->execute([$currentUserId]);
    $_last = $_lastStmt->fetch(PDO::FETCH_ASSOC);

    $subHeroState = 1;
    if ($_last && BusinessConfig::ANALYSIS_MIN_INTERVAL_DAYS > 0) {
        $_lastTs      = strtotime($_last['completed_at'] ?? $_last['updated_at']);
        $_daysPassed  = (int)floor((time() - $_lastTs) / 86400);
        if ($_daysPassed < BusinessConfig::ANALYSIS_MIN_INTERVAL_DAYS) {
            $subHeroState = 2;
        }
    }
}

// ── Paywall: нужно показывать стену оплаты ───────────────────────────────────
// При SUBSCRIPTION_REQUIRED = false — paywall полностью отключён
$subPaywallActive = BusinessConfig::SUBSCRIPTION_REQUIRED
    && ($subHeroState === 3 || $subHeroState === 4);

// ── Дата сгорания следующего слота ────────────────────────────────────────────
$subBurnDate = $subscription ? BusinessConfig::getBurnDate($subscription) : null;
