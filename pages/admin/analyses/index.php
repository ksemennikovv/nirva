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
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($search) {
    $where[]  = '(a.topic LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($status) {
    $where[]  = 'a.status = ?';
    $params[] = $status;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)$db->prepare("SELECT COUNT(*) FROM analysis_sessions a JOIN users u ON u.id = a.user_id $whereSQL")->execute($params) ? $db->prepare("SELECT COUNT(*) FROM analysis_sessions a JOIN users u ON u.id = a.user_id $whereSQL")->execute($params) : 0;
$cntStmt = $db->prepare("SELECT COUNT(*) FROM analysis_sessions a JOIN users u ON u.id = a.user_id $whereSQL");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();
$pages = (int)ceil($total / $perPage);

$stmt = $db->prepare("SELECT a.id, a.topic, a.status, a.created_at, a.selected_practice, u.email, u.id as user_id
    FROM analysis_sessions a JOIN users u ON u.id = a.user_id
    $whereSQL ORDER BY a.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$analyses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statuses = ['created','active','completed','abandoned'];

$pageTitle = 'Разборы';
$activeNav = 'analyses';
require dirname(__DIR__) . '/_layout.php';
?>

<div class="adm-card">
    <div class="adm-card__head">
        <div class="adm-card__title">Всего: <?php echo $total; ?></div>
        <form class="adm-search" method="get" style="flex-wrap:wrap">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Тема или email...">
            <select name="status" style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
                <option value="">Все статусы</option>
                <?php foreach ($statuses as $s): ?>
                <option value="<?php echo $s; ?>" <?php echo $status === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="adm-btn adm-btn--primary adm-btn--sm">Найти</button>
            <?php if ($search || $status): ?><a href="/admin/analyses/" class="adm-btn adm-btn--ghost adm-btn--sm">Сбросить</a><?php endif; ?>
        </form>
    </div>

    <table class="adm-table">
        <thead>
            <tr><th>ID</th><th>Тема</th><th>Статус</th><th>Практика</th><th>Пользователь</th><th>Дата</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($analyses as $a):
            $statusMap = ['created'=>'pending','active'=>'generating','completed'=>'active','abandoned'=>'cancelled'];
            $cls = 'adm-badge--' . ($statusMap[$a['status']] ?? 'none');
        ?>
        <tr>
            <td style="color:var(--muted)"><?php echo $a['id']; ?></td>
            <td><a href="/admin/analyses/view.php?id=<?php echo $a['id']; ?>" style="color:var(--accent);text-decoration:none"><?php echo htmlspecialchars($a['topic'] ?: '(без темы)'); ?></a></td>
            <td><span class="adm-badge <?php echo $cls; ?>"><?php echo $a['status']; ?></span></td>
            <td style="font-size:12px;color:var(--muted)"><?php echo htmlspecialchars($a['selected_practice'] ?? '—'); ?></td>
            <td><a href="/admin/users/view.php?id=<?php echo $a['user_id']; ?>" style="color:var(--muted);font-size:12px;text-decoration:none"><?php echo htmlspecialchars($a['email']); ?></a></td>
            <td style="color:var(--muted)"><?php echo date('d.m.Y', strtotime($a['created_at'])); ?></td>
            <td><a href="/admin/analyses/view.php?id=<?php echo $a['id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">Читать</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($analyses)): ?>
        <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)">Разборов не найдено</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
    <div class="adm-pagination">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a href="?page=<?php echo $p; ?><?php echo $search ? '&q=' . urlencode($search) : ''; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?>"
           class="<?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
