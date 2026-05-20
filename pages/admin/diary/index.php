<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$db     = Database::getConnection();
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($search) {
    $where[]  = 'u.email LIKE ?';
    $params[] = '%' . $search . '%';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cntStmt = $db->prepare("SELECT COUNT(*) FROM diary_entries d JOIN users u ON u.id = d.user_id $whereSQL");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();
$pages = (int)ceil($total / $perPage);

$stmt = $db->prepare("SELECT d.id, d.summary, d.created_at, u.email, u.id AS user_id
    FROM diary_entries d JOIN users u ON u.id = d.user_id
    $whereSQL ORDER BY d.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Дневник';
$activeNav = 'diary';
require dirname(__DIR__) . '/_layout.php';
?>

<div class="adm-card">
    <div class="adm-card__head">
        <div class="adm-card__title">Всего: <?php echo $total; ?></div>
        <form class="adm-search" method="get">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Email пользователя...">
            <button type="submit" class="adm-btn adm-btn--primary adm-btn--sm">Найти</button>
            <?php if ($search): ?><a href="/admin/diary/" class="adm-btn adm-btn--ghost adm-btn--sm">Сбросить</a><?php endif; ?>
        </form>
    </div>

    <table class="adm-table">
        <thead>
            <tr><th>ID</th><th>Пользователь</th><th>Краткое содержание</th><th>Дата</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $e): ?>
        <tr>
            <td style="color:var(--muted)"><?php echo $e['id']; ?></td>
            <td><a href="/admin/users/view.php?id=<?php echo $e['user_id']; ?>" style="color:var(--accent);text-decoration:none;font-size:12px"><?php echo htmlspecialchars($e['email']); ?></a></td>
            <td style="font-size:13px;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars(mb_substr($e['summary'] ?? '—', 0, 120)); ?></td>
            <td style="color:var(--muted)"><?php echo date('d.m.Y H:i', strtotime($e['created_at'])); ?></td>
            <td><a href="/admin/diary/view.php?id=<?php echo $e['id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">Открыть</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($entries)): ?>
        <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--muted)">Записей нет</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
    <div class="adm-pagination">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a href="?page=<?php echo $p; ?><?php echo $search ? '&q=' . urlencode($search) : ''; ?>"
           class="<?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
