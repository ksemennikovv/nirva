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
     * до отправки пользователю.
     * Значение по умолчанию — переопределяется через app_settings (ключ supervisor_mode).
     */
    public const SUPERVISOR_MODE = true;

    // ── Динамические настройки из app_settings ───────────────────────────────

    /** Кэш всех настроек из app_settings (загружается одним запросом). */
    private static ?array $settingsCache = null;

    /** Загружает все настройки из БД в статический кэш. */
    private static function loadSettings(): void
    {
        if (self::$settingsCache !== null) return;
        try {
            require_once __DIR__ . '/../src/services/Database/Database.php';
            $rows = Database::getConnection()
                ->query('SELECT key_name, value FROM app_settings')
                ->fetchAll(PDO::FETCH_KEY_PAIR);
            self::$settingsCache = $rows ?: [];
        } catch (\Throwable $e) {
            self::$settingsCache = [];
        }
    }

    /**
     * Возвращает значение настройки из БД, или $default если не задана.
     * Все настройки загружаются одним запросом и кэшируются на время запроса.
     */
    public static function setting(string $key, $default = null): mixed
    {
        self::loadSettings();
        return array_key_exists($key, self::$settingsCache)
            ? self::$settingsCache[$key]
            : $default;
    }

    // ── Типизированные аксессоры (DB override → константа-умолчание) ──────────

    public static function isSupervisorMode(): bool
    {
        return (bool)(int)self::setting('supervisor_mode', self::SUPERVISOR_MODE ? '1' : '0');
    }

    public static function isSubscriptionRequired(): bool
    {
        return (bool)(int)self::setting('subscription_required', self::SUBSCRIPTION_REQUIRED ? '1' : '0');
    }

    public static function analysisMinIntervalDays(): int
    {
        return (int)self::setting('analysis_min_interval_days', self::ANALYSIS_MIN_INTERVAL_DAYS);
    }

    public static function burnPeriodDays(): int
    {
        return (int)self::setting('burn_period_days', self::BURN_PERIOD_DAYS);
    }

    public static function burnShowMinAnalyses(): int
    {
        return (int)self::setting('burn_show_min_analyses', self::BURN_SHOW_MIN_ANALYSES);
    }

    public static function dashboardDiaryDaysThreshold(): int
    {
        return (int)self::setting('dashboard_diary_days_threshold', self::DASHBOARD_DIARY_DAYS_THRESHOLD);
    }

    public static function dashboardDiaryDailyShowLimit(): int
    {
        return (int)self::setting('dashboard_diary_daily_show_limit', self::DASHBOARD_DIARY_DAILY_SHOW_LIMIT);
    }

    public static function diaryFreeEntriesLimit(): int
    {
        return (int)self::setting('diary_free_entries_limit', self::DIARY_FREE_ENTRIES_LIMIT);
    }

    public static function meditationAutoGenerate(): bool
    {
        return self::setting('meditation_auto_generate', self::MEDITATION_AUTO_GENERATE) === 'yes';
    }

    public static function meditationGenerateCount(): int
    {
        return (int)self::setting('meditation_generate_count', self::MEDITATION_GENERATE_COUNT);
    }

    /** Возвращает секунды. В БД хранятся дни (поле meditation_free_window_days). */
    public static function meditationFreeWindowSeconds(): int
    {
        $days = (int)self::setting('meditation_free_window_days', 30);
        return $days * 86400;
    }

    /** Возвращает долю 0–1. В БД хранится % (поле meditation_set_discount_pct). */
    // ── Генерация изображений ─────────────────────────────────────────────────

    public static function imageProviderPersonal(): string
    {
        return self::setting('image_provider_personal', 'none');
    }

    public static function imageProviderGeneral(): string
    {
        return self::setting('image_provider_general', 'none');
    }

    public static function meditationSetDiscount(): float
    {
        return (int)self::setting('meditation_set_discount_pct', (int)(self::MEDITATION_SET_DISCOUNT * 100)) / 100.0;
    }

    public static function referralBonusMonths(): int
    {
        return (int)self::setting('referral_bonus_months', self::REFERRAL_BONUS_MONTHS);
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

        if ($analysesPerMonth <= self::burnShowMinAnalyses() || $remaining <= 0) return null;

        $slotDays = self::burnPeriodDays() / $analysesPerMonth;
        $nextSlot = (int)$subscription['analyses_used'] + 1;
        $startsAt = strtotime($subscription['starts_at']);
        $burnTs   = $startsAt + (int)($nextSlot * $slotDays * 86400);

        return date('d.m.Y', $burnTs);
    }
}
