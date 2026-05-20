<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/repositories/UserRepository.php';
require_once $root . '/src/repositories/ProfileParameterRepository.php';

$userId = (int)($_GET['user_id'] ?? 0);
if (!$userId) { http_response_code(400); echo 'Bad request'; exit; }

$userRepo    = new UserRepository();
$user        = $userRepo->getWithDetails($userId);
if (!$user) { http_response_code(404); echo 'Пользователь не найден'; exit; }

$profileRepo = new ProfileParameterRepository();
$allParams   = $profileRepo->getAllParameters();
$userValues  = $profileRepo->getUserValues($userId);
$memories    = $profileRepo->getTopMemories($userId, 20);
$history     = $profileRepo->getHistory($userId, 30);

// Group params by category
$grouped = [];
foreach ($allParams as $p) {
    $cat = $p['category'] ?? 'other';
    $grouped[$cat][] = $p;
}

$pageTitle = 'Портрет: ' . $user['email'];
$activeNav = 'users';
require dirname(__DIR__) . '/_layout.php';
?>

<div style="display:flex;gap:16px;align-items:center;margin-bottom:20px">
    <a href="/admin/users/view.php?id=<?php echo $userId; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">← <?php echo htmlspecialchars($user['email']); ?></a>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

<!-- Параметры профиля -->
<div class="adm-card">
    <div class="adm-card__head"><div class="adm-card__title">Параметры профиля</div></div>
    <div style="padding:16px 20px">
    <?php foreach ($grouped as $category => $params): ?>
        <div style="margin-bottom:20px">
            <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px"><?php echo htmlspecialchars($category); ?></div>
            <?php foreach ($params as $p):
                $val = $userValues[$p['code']] ?? null;
                if (!$val || empty($val)) continue;
                if (is_array($val) && isset($val[0]) && is_array($val[0])) {
                    $display = implode(', ', array_column($val, 'value'));
                } elseif (is_array($val)) {
                    $display = implode(', ', $val);
                } else {
                    $display = $val;
                }
                if (!$display) continue;
            ?>
            <div class="adm-portrait-param">
                <span class="adm-portrait-param__label"><?php echo htmlspecialchars($p['label'] ?? $p['code']); ?></span>
                <span class="adm-portrait-param__value"><?php echo htmlspecialchars($display); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php if (empty($userValues)): ?>
    <div style="color:var(--muted);padding:12px 0">Параметры ещё не заполнены</div>
    <?php endif; ?>
    </div>
</div>

<!-- AI-воспоминания -->
<div class="adm-card">
    <div class="adm-card__head"><div class="adm-card__title">AI-воспоминания (<?php echo count($memories); ?>)</div></div>
    <div style="padding:16px 20px;display:flex;flex-direction:column;gap:10px">
    <?php foreach ($memories as $mem): ?>
        <div style="padding:10px 12px;background:var(--bg);border-radius:6px;font-size:13px;line-height:1.5">
            <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                <span style="font-weight:600;color:var(--text);font-size:12px"><?php echo htmlspecialchars($mem['category'] ?? 'general'); ?></span>
                <?php if (isset($mem['importance'])): ?>
                <span style="color:var(--muted);font-size:11px">важность: <?php echo $mem['importance']; ?>/10</span>
                <?php endif; ?>
            </div>
            <div style="color:var(--text)"><?php echo nl2br(htmlspecialchars($mem['content'] ?? '')); ?></div>
            <div style="color:var(--muted);font-size:11px;margin-top:4px"><?php echo date('d.m.Y', strtotime($mem['created_at'])); ?></div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($memories)): ?>
    <div style="color:var(--muted)">Воспоминаний нет</div>
    <?php endif; ?>
    </div>
</div>

</div>

<!-- История изменений -->
<?php if (!empty($history)): ?>
<div class="adm-card">
    <div class="adm-card__head"><div class="adm-card__title">История изменений параметров</div></div>
    <table class="adm-table">
        <thead><tr><th>Параметр</th><th>Событие</th><th>Источник</th><th>Данные</th><th>Дата</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
        <tr>
            <td style="font-size:12px"><?php echo htmlspecialchars($h['label'] ?? $h['code'] ?? ''); ?></td>
            <td><span class="adm-badge adm-badge--<?php echo $h['event_type'] === 'added' ? 'active' : ($h['event_type'] === 'removed' ? 'cancelled' : 'pending'); ?>"><?php echo $h['event_type']; ?></span></td>
            <td style="color:var(--muted);font-size:12px"><?php echo $h['source_type']; ?> #<?php echo $h['source_id']; ?></td>
            <td style="font-size:12px;color:var(--muted);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars(mb_substr($h['event_data'] ?? '', 0, 80)); ?></td>
            <td style="color:var(--muted)"><?php echo date('d.m.Y H:i', strtotime($h['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
