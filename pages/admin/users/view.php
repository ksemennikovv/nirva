<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/repositories/UserRepository.php';
require_once $root . '/src/repositories/AnalysisRepository.php';
require_once $root . '/src/repositories/ProfileParameterRepository.php';

$userId   = (int)($_GET['id'] ?? 0);
$userRepo = new UserRepository();
$user     = $userRepo->getWithDetails($userId);

if (!$user) {
    http_response_code(404);
    echo 'Пользователь не найден'; exit;
}

$db = Database::getConnection();

// История разборов
$analyses = $db->prepare("SELECT id, topic, status, created_at, selected_practice FROM analysis_sessions WHERE user_id = ? ORDER BY id DESC LIMIT 20");
$analyses->execute([$userId]);
$analyses = $analyses->fetchAll(PDO::FETCH_ASSOC);

// Все подписки
$subs = $db->prepare("SELECT * FROM subscriptions WHERE user_id = ? ORDER BY id DESC");
$subs->execute([$userId]);
$subs = $subs->fetchAll(PDO::FETCH_ASSOC);

// Профиль параметры
$profileRepo = new ProfileParameterRepository();
$profileValsRaw = $profileRepo->getUserValues($userId);
$allParams      = $profileRepo->getAllParameters();
$paramLabels    = array_column($allParams, 'label', 'code');
$profileVals    = [];
foreach ($profileValsRaw as $code => $items) {
    if (empty($items)) continue;
    $display = is_array($items) && isset($items[0]) && is_array($items[0])
        ? implode(', ', array_column($items, 'value'))
        : (is_array($items) ? implode(', ', $items) : $items);
    if ($display) $profileVals[] = ['label' => $paramLabels[$code] ?? $code, 'display' => $display];
}
$memories    = $profileRepo->getTopMemories($userId, 10);

$flash = $_GET['saved'] ?? '';

$pageTitle = 'Пользователь: ' . $user['email'];
$activeNav = 'users';
require dirname(__DIR__) . '/_layout.php';
?>

<?php if ($flash === '1'): ?><div class="adm-alert adm-alert--success">Изменения сохранены.</div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

    <!-- Основные данные -->
    <div class="adm-card">
        <div class="adm-card__head"><div class="adm-card__title">Основное</div></div>
        <div style="padding:20px">
            <form class="adm-form" method="post" action="/admin/users/api/update.php">
                <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                <div class="adm-field">
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>">
                </div>
                <div class="adm-field">
                    <label>Роль</label>
                    <select name="role">
                        <option value="user"  <?php echo $user['role'] === 'user'  ? 'selected' : ''; ?>>Пользователь</option>
                        <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Администратор</option>
                    </select>
                </div>
                <div style="display:flex;gap:10px;align-items:center">
                    <button type="submit" class="adm-btn adm-btn--primary">Сохранить</button>
                    <span style="color:var(--muted);font-size:12px">ID: <?php echo $user['id']; ?> · Зарегистрирован: <?php echo date('d.m.Y', strtotime($user['created_at'])); ?></span>
                </div>
            </form>
        </div>
        <div style="padding:0 20px 16px;display:flex;gap:16px;color:var(--muted);font-size:13px">
            <span>📋 Разборов: <b><?php echo $user['analyses_count']; ?></b></span>
            <span>📔 Дневник: <b><?php echo $user['diary_count']; ?></b></span>
            <span>🎧 Медитаций: <b><?php echo $user['meditations_count']; ?></b></span>
        </div>
    </div>

    <!-- Управление подпиской -->
    <div class="adm-card">
        <div class="adm-card__head"><div class="adm-card__title">Подписка</div></div>
        <div style="padding:20px">
            <?php if ($user['subscription']): $s = $user['subscription']; ?>
            <div style="margin-bottom:16px;padding:12px;background:var(--bg);border-radius:6px;font-size:13px">
                <div style="margin-bottom:6px">
                    <span class="adm-badge adm-badge--<?php echo $s['plan']; ?>"><?php echo $s['plan']; ?></span>
                    <span class="adm-badge adm-badge--<?php echo $s['status']; ?>" style="margin-left:6px"><?php echo $s['status']; ?></span>
                </div>
                <div style="color:var(--muted)">
                    Разборов: <?php echo $s['analyses_used']; ?> / <?php echo $s['analyses_per_month']; ?> &nbsp;·&nbsp;
                    До: <?php echo date('d.m.Y', strtotime($s['expires_at'])); ?>
                </div>
            </div>
            <form class="adm-form" method="post" action="/admin/subscriptions/api/update.php">
                <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                <input type="hidden" name="redirect" value="/admin/users/view.php?id=<?php echo $userId; ?>&saved=1">
                <div class="adm-form-row">
                    <div class="adm-field">
                        <label>Тариф</label>
                        <select name="plan">
                            <?php foreach (['start','basic','transformation'] as $p): ?>
                            <option value="<?php echo $p; ?>" <?php echo $s['plan'] === $p ? 'selected' : ''; ?>><?php echo ucfirst($p); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="adm-field">
                        <label>Статус</label>
                        <select name="status">
                            <option value="active"    <?php echo $s['status'] === 'active'    ? 'selected' : ''; ?>>Активна</option>
                            <option value="cancelled" <?php echo $s['status'] === 'cancelled' ? 'selected' : ''; ?>>Отменена</option>
                            <option value="expired"   <?php echo $s['status'] === 'expired'   ? 'selected' : ''; ?>>Истекла</option>
                        </select>
                    </div>
                </div>
                <div class="adm-form-row">
                    <div class="adm-field">
                        <label>Разборов в месяц</label>
                        <input type="number" name="analyses_per_month" value="<?php echo $s['analyses_per_month']; ?>" min="1" max="30">
                    </div>
                    <div class="adm-field">
                        <label>Использовано (сброс)</label>
                        <input type="number" name="analyses_used" value="<?php echo $s['analyses_used']; ?>" min="0">
                    </div>
                </div>
                <div class="adm-field">
                    <label>Действует до</label>
                    <input type="date" name="expires_at" value="<?php echo date('Y-m-d', strtotime($s['expires_at'])); ?>">
                </div>
                <button type="submit" class="adm-btn adm-btn--primary">Обновить подписку</button>
            </form>
            <?php else: ?>
            <p style="color:var(--muted);margin-bottom:16px">Нет активной подписки</p>
            <?php endif; ?>

            <hr style="margin:16px 0;border:none;border-top:1px solid var(--border)">
            <div style="font-size:13px;font-weight:600;margin-bottom:12px;color:var(--muted)">НАЗНАЧИТЬ НОВУЮ ПОДПИСКУ</div>
            <form class="adm-form" method="post" action="/admin/subscriptions/api/create.php">
                <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
                <input type="hidden" name="redirect" value="/admin/users/view.php?id=<?php echo $userId; ?>&saved=1">
                <div class="adm-form-row">
                    <div class="adm-field">
                        <label>Тариф</label>
                        <select name="plan">
                            <option value="start">Start</option>
                            <option value="basic">Basic</option>
                            <option value="transformation">Transformation</option>
                        </select>
                    </div>
                    <div class="adm-field">
                        <label>Разборов в месяц</label>
                        <input type="number" name="analyses_per_month" value="1" min="1" max="30">
                    </div>
                </div>
                <div class="adm-form-row">
                    <div class="adm-field">
                        <label>Начало</label>
                        <input type="date" name="starts_at" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="adm-field">
                        <label>Конец</label>
                        <input type="date" name="expires_at" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                    </div>
                </div>
                <button type="submit" class="adm-btn adm-btn--primary">Назначить</button>
            </form>
        </div>
    </div>
</div>

<!-- История разборов -->
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">История разборов</div></div>
    <table class="adm-table">
        <thead><tr><th>ID</th><th>Тема</th><th>Статус</th><th>Практика</th><th>Дата</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($analyses as $a):
            $statusMap = ['created'=>'pending','active'=>'generating','completed'=>'active','abandoned'=>'cancelled'];
            $cls = 'adm-badge--' . ($statusMap[$a['status']] ?? 'none');
        ?>
        <tr>
            <td style="color:var(--muted)"><?php echo $a['id']; ?></td>
            <td><?php echo htmlspecialchars($a['topic'] ?: '—'); ?></td>
            <td><span class="adm-badge <?php echo $cls; ?>"><?php echo $a['status']; ?></span></td>
            <td style="color:var(--muted);font-size:12px"><?php echo htmlspecialchars($a['selected_practice'] ?? '—'); ?></td>
            <td style="color:var(--muted)"><?php echo date('d.m.Y', strtotime($a['created_at'])); ?></td>
            <td><a href="/admin/analyses/view.php?id=<?php echo $a['id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">Открыть</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($analyses)): ?>
        <tr><td colspan="6" style="text-align:center;padding:20px;color:var(--muted)">Разборов нет</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Психоэмоциональный портрет -->
<?php if (!empty($profileVals) || !empty($memories)): ?>
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head">
        <div class="adm-card__title">Психоэмоциональный портрет</div>
        <a href="/admin/portrait/view.php?user_id=<?php echo $userId; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">Подробнее →</a>
    </div>
    <div style="padding:16px 20px;display:flex;flex-direction:column;gap:8px">
    <?php foreach (array_slice($profileVals, 0, 6) as $v): ?>
        <div style="display:flex;gap:12px;font-size:13px">
            <span style="color:var(--muted);width:200px;flex-shrink:0"><?php echo htmlspecialchars($v['label']); ?></span>
            <span><?php echo htmlspecialchars($v['display']); ?></span>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Удалить пользователя -->
<div style="margin-top:24px;padding:16px;background:#fff5f5;border:1px solid #fecaca;border-radius:8px">
    <div style="font-weight:600;margin-bottom:8px;color:var(--danger)">Удалить пользователя</div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:12px">Удалит аккаунт и все связанные данные. Действие необратимо.</p>
    <form method="post" action="/admin/users/api/delete.php" onsubmit="return confirm('Удалить пользователя <?php echo htmlspecialchars($user['email']); ?>? Это действие нельзя отменить.')">
        <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
        <button type="submit" class="adm-btn adm-btn--danger">Удалить пользователя</button>
    </form>
</div>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
