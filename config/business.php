<?php

/**
 * Бизнес-параметры приложения Nirva.
 * Все числовые константы логики — здесь. Не хардкодить в страницах.
 */
final class BusinessConfig
{
    // ── Тарифные планы ────────────────────────────────────────────────────────

    /** Количество разборов в месяц для каждого плана. */
    public const PLAN_ANALYSES = [
        'start'          => 1,
        'basic'          => 2,
        'transformation' => 8,
    ];

    /** Человекочитаемые названия планов. */
    public const PLAN_LABELS = [
        'start'          => 'Старт',
        'basic'          => 'Базовый',
        'transformation' => 'Трансформация',
    ];

    /** Краткое описание плана для биллинга. */
    public const PLAN_DETAILS = [
        'start'          => '1 разбор в месяц',
        'basic'          => '2 разбора в месяц',
        'transformation' => '4 разбора в месяц',
    ];

    // ── Цены (рубли) ─────────────────────────────────────────────────────────

    /** Цены: plan → period → рублей. */
    public const PRICES = [
        'start'          => ['monthly' => 5000,  '6months' => 27000,  '12months' => 48000],
        'basic'          => ['monthly' => 9500,  '6months' => 51300,  '12months' => 91200],
        'transformation' => ['monthly' => 18000, '6months' => 97200,  '12months' => 172800],
    ];

    // ── Логика разборов ───────────────────────────────────────────────────────

    /**
     * Длина расчётного периода для вычисления дедлайна сгорания слота (дней).
     * Слот = BURN_PERIOD_DAYS / analyses_per_month.
     */
    public const BURN_PERIOD_DAYS = 30;

    /**
     * Показывать дедлайн сгорания только если разборов в месяц больше этого числа.
     * При 1 разборе дедлайн не нужен.
     */
    public const BURN_SHOW_MIN_ANALYSES = 1;

    /**
     * Минимальный интервал между разборами (дней).
     * Даже если у пользователя несколько разборов в месяц — следующий
     * доступен не раньше чем через это количество дней от предыдущего.
     */
    public const ANALYSIS_MIN_INTERVAL_DAYS = 0;

    // ── Дашборд: переключение режима дневник / разбор ─────────────────────────

    /**
     * Сколько дней после завершённого разбора на первом экране показывать блок дневника.
     * Если прошло меньше — дневник, иначе — приглашение к новому разбору.
     */
    public const DASHBOARD_DIARY_DAYS_THRESHOLD = 5;

    /**
     * Сколько раз за сегодня пользователь может открыть блок дневника с главного экрана.
     * После достижения лимита блок переключается на режим разбора.
     */
    public const DASHBOARD_DIARY_DAILY_SHOW_LIMIT = 1;

    // ── Дневник ───────────────────────────────────────────────────────────────

    /** Сколько записей в дневнике доступно бесплатно (без подписки). */
    public const DIARY_FREE_ENTRIES_LIMIT = 10;

    // ── Медитации ─────────────────────────────────────────────────────────────

    /**
     * Автоматически генерировать медитации после завершения разбора.
     * 'yes' — генерировать, 'no' — не генерировать.
     */
    public const MEDITATION_AUTO_GENERATE = 'yes';

    /**
     * Количество медитаций, создаваемых за один цикл генерации (после разбора).
     */
    public const MEDITATION_GENERATE_COUNT = 1;

    /**
     * Окно бесплатного доступа к медитациям is_free_first_month (в секундах).
     * По умолчанию — 1 месяц.
     */
    public const MEDITATION_FREE_WINDOW_SECONDS = 2592000; // 30 * 24 * 3600

    /**
     * Скидка на покупку набора медитаций из разбора (доля от 0 до 1).
     * Передаётся в JS через data-атрибут или inline JS-переменную.
     */
    public const MEDITATION_SET_DISCOUNT = 0.15;

    // ── Доступ к приложению ───────────────────────────────────────────────────

    /**
     * Требовать подписку для доступа к разборам.
     * true  — стандартная логика: подписка обязательна, paywall активен.
     * false — режим тестирования: все пользователи имеют полный доступ без подписки.
     */
    public const SUBSCRIPTION_REQUIRED = false;

    // ── Режим отладки психолога ───────────────────────────────────────────────

    /**
     * Supervisor Mode: все ответы ИИ проходят ручную проверку администратором
     * до отправки пользователю. Включить для тестирования алгоритма на реальных
     * пользователях перед масштабированием.
     * true — включён, false — автоматический режим (стандартная работа).
     * Значение по умолчанию — переопределяется через app_settings (ключ supervisor_mode).
     */
    public const SUPERVISOR_MODE = true;

    /**
     * Динамическая проверка Supervisor Mode из app_settings.
     * Приоритет: БД → константа-умолчание.
     */
    public static function isSupervisorMode(): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            require_once __DIR__ . '/../src/services/Database/Database.php';
            $stmt = Database::getConnection()->prepare('SELECT value FROM app_settings WHERE key_name = ?');
            $stmt->execute(['supervisor_mode']);
            $row = $stmt->fetchColumn();
            $cached = ($row !== false) ? (bool)(int)$row : self::SUPERVISOR_MODE;
        } catch (\Throwable $e) {
            $cached = self::SUPERVISOR_MODE;
        }
        return $cached;
    }

    // ── Реферальная программа ────────────────────────────────────────────────

    /** Бонус реферера — дополнительные месяцы подписки за каждого приглашённого. */
    public const REFERRAL_BONUS_MONTHS = 1;

    // ── Вспомогательные методы ───────────────────────────────────────────────

    /** Цена плана/периода или 0. */
    public static function getPrice(string $plan, string $period): int
    {
        return self::PRICES[$plan][$period] ?? 0;
    }

    /** Количество разборов в месяц для плана. */
    public static function getPlanAnalyses(string $plan): int
    {
        return self::PLAN_ANALYSES[$plan] ?? 1;
    }

    /**
     * Дата сгорания следующего слота разбора.
     * Возвращает строку 'dd.mm.YYYY' или null если показывать не нужно.
     */
    public static function getBurnDate(array $subscription): ?string
    {
        if (!$subscription) return null;
        $analysesPerMonth = (int)$subscription['analyses_per_month'];
        $remaining        = max(0, $analysesPerMonth - (int)$subscription['analyses_used']);

        if ($analysesPerMonth <= self::BURN_SHOW_MIN_ANALYSES || $remaining <= 0) return null;

        $slotDays = self::BURN_PERIOD_DAYS / $analysesPerMonth;
        $nextSlot = (int)$subscription['analyses_used'] + 1;
        $startsAt = strtotime($subscription['starts_at']);
        $burnTs   = $startsAt + (int)($nextSlot * $slotDays * 86400);

        return date('d.m.Y', $burnTs);
    }
}
