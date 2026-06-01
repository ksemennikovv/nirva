<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/repositories/AppSettingsRepository.php';

$settings = new AppSettingsRepository();
$flash    = $_GET['saved'] ?? '';

// Read overrides from DB (fallback to BusinessConfig)
$plans   = ['start', 'basic', 'transformation'];
$periods = ['monthly', '6months', '12months'];

$data = [];
foreach ($plans as $p) {
    foreach ($periods as $per) {
        $key = "price_{$p}_{$per}";
        $data[$p][$per] = (int)($settings->get($key) ?? BusinessConfig::PRICES[$p][$per]);
    }
    $data[$p]['analyses_per_month'] = (int)($settings->get("analyses_per_month_{$p}") ?? BusinessConfig::PLAN_ANALYSES[$p]);
}

$intervalDays     = BusinessConfig::analysisMinIntervalDays();
$burnPeriod       = BusinessConfig::burnPeriodDays();
$diaryFreeEntries = BusinessConfig::diaryFreeEntriesLimit();

$pageTitle = 'Тарифы';
$activeNav = 'tariffs';
require dirname(__DIR__) . '/_layout.php';
?>

<?php if ($flash === '1'): ?><div class="adm-alert adm-alert--success">Настройки сохранены.</div><?php endif; ?>

<form method="post" action="/admin/tariffs/api/save.php">

<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">Цены и лимиты по тарифам</div></div>
    <div style="padding:20px;overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
                <tr>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Тариф</th>
                    <th style="padding:8px;border-bottom:1px solid var(--border)">Разборов/мес</th>
                    <th style="padding:8px;border-bottom:1px solid var(--border)">Ежемесячно ₽</th>
                    <th style="padding:8px;border-bottom:1px solid var(--border)">6 месяцев ₽</th>
                    <th style="padding:8px;border-bottom:1px solid var(--border)">12 месяцев ₽</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($plans as $p): ?>
            <tr>
                <td style="padding:8px;font-weight:600"><span class="adm-badge adm-badge--<?php echo $p; ?>"><?php echo ucfirst($p); ?></span></td>
                <td style="padding:8px;text-align:center">
                    <input type="number" name="analyses_per_month_<?php echo $p; ?>" value="<?php echo $data[$p]['analyses_per_month']; ?>" min="1" max="30" style="width:70px;padding:6px;border:1px solid var(--border);border-radius:4px;text-align:center">
                </td>
                <?php foreach ($periods as $per): ?>
                <td style="padding:8px;text-align:center">
                    <input type="number" name="price_<?php echo $p; ?>_<?php echo $per; ?>" value="<?php echo $data[$p][$per]; ?>" min="0" style="width:90px;padding:6px;border:1px solid var(--border);border-radius:4px;text-align:center">
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">Логика приложения</div></div>
    <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px">
        <div class="adm-field">
            <label>Мин. интервал между разборами (дней)</label>
            <input type="number" name="analysis_min_interval_days" value="<?php echo $intervalDays; ?>" min="0" max="365">
        </div>
        <div class="adm-field">
            <label>Период сгорания слота (дней)</label>
            <input type="number" name="burn_period_days" value="<?php echo $burnPeriod; ?>" min="1" max="365">
        </div>
        <div class="adm-field">
            <label>Бесплатных записей в дневнике</label>
            <input type="number" name="diary_free_entries_limit" value="<?php echo $diaryFreeEntries; ?>" min="0" max="1000">
        </div>
    </div>
</div>

<button type="submit" class="adm-btn adm-btn--primary">Сохранить настройки</button>
</form>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
