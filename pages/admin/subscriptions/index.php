<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$db     = Database::getConnection();
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$plan   = $_GET['plan'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($search) {
    $where[]  = 'u.email LIKE ?';
    $params[] = '%' . $search . '%';
}
if ($status) {
    $where[]  = 's.status = ?';
    $params[] = $status;
}
if ($plan) {
    $where[]  = 's.plan = ?';
    $params[] = $plan;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cntStmt = $db->prepare("SELECT COUNT(*) FROM subscriptions s JOIN users u ON u.id = s.user_id $whereSQL");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();
$pages = (int)ceil($total / $perPage);

$stmt = $db->prepare("SELECT s.*, u.email, u.id AS user_id
    FROM subscriptions s JOIN users u ON u.id = s.user_id
    $whereSQL ORDER BY s.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Подписки';
$activeNav = 'subscriptions';
require dirname(__DIR__) . '/_layout.php';
?>

<div class="adm-card">
    <div class="adm-card__head">
        <div class="adm-card__title">Всего: <?php echo $total; ?></div>
        <form class="adm-search" method="get" style="flex-wrap:wrap">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Email...">
            <select name="plan" style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
                <option value="">Все планы</option>
                <?php foreach (['start','basic','transformation'] as $p): ?>
                <option value="<?php echo $p; ?>" <?php echo $plan === $p ? 'selected' : ''; ?>><?php echo ucfirst($p); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
                <option value="">Все статусы</option>
                <?php foreach (['active','cancelled','expired'] as $st): ?>
                <option value="<?php echo $st; ?>" <?php echo $status === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="adm-btn adm-btn--primary adm-btn--sm">Найти</button>
            <?php if ($search || $status || $plan): ?><a href="/admin/subscriptions/" class="adm-btn adm-btn--ghost adm-btn--sm">Сбросить</a><?php endif; ?>
        </form>
    </div>

    <table class="adm-table">
        <thead>
            <tr><th>ID</th><th>Пользователь</th><th>Тариф</th><th>Статус</th><th>Разборов</th><th>Действует до</th><th>Создана</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($subs as $s):
            $planCls = 'adm-badge--' . $s['plan'];
            $stCls   = ['active'=>'active','cancelled'=>'cancelled','expired'=>'cancelled'][$s['status']] ?? 'none';
        ?>
        <tr>
            <td style="color:var(--muted)"><?php echo $s['id']; ?></td>
            <td><a href="/admin/users/view.php?id=<?php echo $s['user_id']; ?>" style="color:var(--accent);text-decoration:none"><?php echo htmlspecialchars($s['email']); ?></a></td>
            <td><span class="adm-badge <?php echo $planCls; ?>"><?php echo $s['plan']; ?></span></td>
            <td><span class="adm-badge adm-badge--<?php echo $stCls; ?>"><?php echo $s['status']; ?></span></td>
            <td style="color:var(--muted)"><?php echo $s['analyses_used']; ?> / <?php echo $s['analyses_per_month']; ?></td>
            <td style="color:var(--muted)"><?php echo date('d.m.Y', strtotime($s['expires_at'])); ?></td>
            <td style="color:var(--muted)"><?php echo date('d.m.Y', strtotime($s['created_at'])); ?></td>
            <td><a href="/admin/users/view.php?id=<?php echo $s['user_id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">Пользователь</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($subs)): ?>
        <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--muted)">Подписок не найдено</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
    <div class="adm-pagination">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a href="?page=<?php echo $p; ?><?php echo $search ? '&q=' . urlencode($search) : ''; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $plan ? '&plan=' . urlencode($plan) : ''; ?>"
           class="<?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
