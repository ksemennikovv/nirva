<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$db     = Database::getConnection();
$status = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($search) {
    $where[]  = '(m.title LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($status) {
    $where[]  = 'm.generation_status = ?';
    $params[] = $status;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cntStmt = $db->prepare("SELECT COUNT(*) FROM meditations m LEFT JOIN users u ON u.id = m.user_id $whereSQL");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();
$pages = (int)ceil($total / $perPage);

$stmt = $db->prepare("SELECT m.id, m.title, m.generation_status, m.type, m.analysis_id, m.full_audio_url, m.created_at, u.email, u.id AS user_id
    FROM meditations m LEFT JOIN users u ON u.id = m.user_id
    $whereSQL ORDER BY m.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$meds = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statuses = ['pending','generating','ready','failed'];

$flash = $_GET['deleted'] ?? '';

$pageTitle = 'Медитации';
$activeNav = 'meditations';
require dirname(__DIR__) . '/_layout.php';
?>
<?php if ($flash === '1'): ?>
<div class="adm-alert adm-alert--success">🗑 Медитация удалена вместе с аудио, картинкой и историей.</div>
<?php endif; ?>

<div style="display:flex;gap:10px;margin-bottom:16px">
    <a href="/admin/meditations/general.php" class="adm-btn adm-btn--ghost adm-btn--sm">🎧 Общие медитации</a>
    <a href="/admin/meditations/categories.php" class="adm-btn adm-btn--ghost adm-btn--sm">📁 Категории</a>
</div>

<div class="adm-card">
    <div class="adm-card__head">
        <div class="adm-card__title">Всего: <?php echo $total; ?></div>
        <form class="adm-search" method="get" style="flex-wrap:wrap">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Название или email...">
            <select name="status" style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
                <option value="">Все статусы</option>
                <?php foreach ($statuses as $s): ?>
                <option value="<?php echo $s; ?>" <?php echo $status === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="adm-btn adm-btn--primary adm-btn--sm">Найти</button>
            <?php if ($search || $status): ?><a href="/admin/meditations/" class="adm-btn adm-btn--ghost adm-btn--sm">Сбросить</a><?php endif; ?>
        </form>
    </div>

    <table class="adm-table">
        <thead>
            <tr><th>ID</th><th>Название</th><th>Статус</th><th>Тип</th><th>Пользователь</th><th>Разбор</th><th>Дата</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($meds as $m):
            $stCls = ['ready'=>'active','pending'=>'pending','failed'=>'cancelled','generating'=>'generating'][$m['generation_status']] ?? 'none';
        ?>
        <tr>
            <td style="color:var(--muted)"><?php echo $m['id']; ?></td>
            <td><?php echo htmlspecialchars($m['title'] ?? '—'); ?></td>
            <td><span class="adm-badge adm-badge--<?php echo $stCls; ?>"><?php echo $m['generation_status']; ?></span></td>
            <td style="color:var(--muted);font-size:12px"><?php echo $m['type']; ?></td>
            <td><?php if ($m['user_id']): ?><a href="/admin/users/view.php?id=<?php echo $m['user_id']; ?>" style="color:var(--muted);font-size:12px;text-decoration:none"><?php echo htmlspecialchars($m['email'] ?? ''); ?></a><?php else: ?>—<?php endif; ?></td>
            <td><?php if ($m['analysis_id']): ?><a href="/admin/analyses/view.php?id=<?php echo $m['analysis_id']; ?>" style="color:var(--muted);font-size:12px">#<?php echo $m['analysis_id']; ?></a><?php else: ?>—<?php endif; ?></td>
            <td style="color:var(--muted)"><?php echo date('d.m.Y', strtotime($m['created_at'])); ?></td>
            <td style="display:flex;gap:6px">
                <a href="/admin/meditations/edit.php?id=<?php echo $m['id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">Изменить</a>
                <?php if ($m['full_audio_url']): ?><a href="<?php echo htmlspecialchars($m['full_audio_url']); ?>" target="_blank" class="adm-btn adm-btn--ghost adm-btn--sm">🎧</a><?php endif; ?>
                <?php if ($m['generation_status'] === 'failed' || $m['generation_status'] === 'pending'): ?>
                <form method="post" action="/admin/meditations/api/regenerate.php" style="display:inline">
                    <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                    <button type="submit" class="adm-btn adm-btn--ghost adm-btn--sm">↺</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($meds)): ?>
        <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--muted)">Медитаций не найдено</td></tr>
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
