<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$db = Database::getConnection();

$search    = trim($_GET['q']      ?? '');
$filterStatus = trim($_GET['status'] ?? ''); // 'done' | 'in_progress'
$page      = max(1, (int)($_GET['page'] ?? 1));
$limit     = 30;
$offset    = ($page - 1) * $limit;
$flash     = $_GET['deleted'] ?? '';

$where  = [];
$params = [];

if ($search) {
    $where[]  = 'u.email LIKE :q';
    $params['q'] = '%' . $search . '%';
}
if ($filterStatus === 'done') {
    $where[] = 'de.summary IS NOT NULL';
} elseif ($filterStatus === 'in_progress') {
    $where[] = 'de.summary IS NULL';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM diary_entries de JOIN users u ON u.id = de.user_id $whereSQL");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = ceil($total / $limit);

$stmt = $db->prepare(
    "SELECT de.id, de.summary, de.created_at,
            u.id as user_id, u.email,
            COUNT(dm.id) as msg_count
     FROM diary_entries de
     JOIN users u ON u.id = de.user_id
     LEFT JOIN diary_messages dm ON dm.diary_entry_id = de.id
     $whereSQL
     GROUP BY de.id
     ORDER BY de.id DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) $stmt->bindValue(':' . $k, $v);
$stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Дневник';
$activeNav = 'diary';
require dirname(__DIR__) . '/_layout.php';
?>

<?php if ($flash === '1'): ?>
<div class="adm-alert adm-alert--success">🗑 Запись дневника удалена.</div>
<?php endif; ?>

<!-- Фильтры -->
<form method="get" style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center">
    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
           placeholder="Поиск по email..." style="padding:7px 12px;border:1px solid var(--border);border-radius:6px;font-size:13px;min-width:220px">
    <select name="status" style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
        <option value=""        <?php echo $filterStatus === ''            ? 'selected' : ''; ?>>Все статусы</option>
        <option value="done"    <?php echo $filterStatus === 'done'        ? 'selected' : ''; ?>>✅ Завершена</option>
        <option value="in_progress" <?php echo $filterStatus === 'in_progress' ? 'selected' : ''; ?>>⏳ В процессе</option>
    </select>
    <button type="submit" class="adm-btn adm-btn--primary">Найти</button>
    <?php if ($search || $filterStatus): ?>
    <a href="/admin/diary/" class="adm-btn adm-btn--ghost">Сбросить</a>
    <?php endif; ?>
</form>

<div class="adm-card">
    <div class="adm-card__head">
        <div class="adm-card__title">Записи дневника (<?php echo $total; ?>)</div>
    </div>
    <table class="adm-table">
        <thead>
            <tr>
                <th style="width:60px">ID</th>
                <th>Пользователь</th>
                <th style="width:100px">Статус</th>
                <th>Тема / Саммари</th>
                <th style="width:60px">Сообщ.</th>
                <th style="width:130px">Дата</th>
                <th style="width:90px"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $e):
            $isDone = !empty($e['summary']);
        ?>
        <tr>
            <td style="color:var(--muted);font-weight:600"><?php echo $e['id']; ?></td>
            <td>
                <a href="/admin/users/view.php?id=<?php echo $e['user_id']; ?>" style="color:var(--accent)">
                    <?php echo htmlspecialchars($e['email']); ?>
                </a>
            </td>
            <td style="white-space:nowrap">
                <?php if ($isDone): ?>
                <span class="adm-badge adm-badge--active" style="text-transform:none;letter-spacing:0">Завершена</span>
                <?php else: ?>
                <span class="adm-badge adm-badge--pending" style="text-transform:none;letter-spacing:0">В процессе</span>
                <?php endif; ?>
            </td>
            <td style="max-width:360px">
                <?php if ($e['summary']): ?>
                <span style="font-size:13px"><?php echo htmlspecialchars(mb_substr($e['summary'], 0, 90)); ?><?php echo mb_strlen($e['summary']) > 90 ? '…' : ''; ?></span>
                <?php else: ?>
                <span style="color:var(--muted);font-size:12px;font-style:italic">Саммари ещё не сформировано</span>
                <?php endif; ?>
            </td>
            <td style="color:var(--muted);text-align:center"><?php echo $e['msg_count']; ?></td>
            <td style="color:var(--muted);white-space:nowrap;font-size:12px"><?php echo date('d.m.Y H:i', strtotime($e['created_at'])); ?></td>
            <td style="white-space:nowrap"><a href="/admin/diary/view.php?id=<?php echo $e['id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm" style="white-space:nowrap">Открыть →</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($entries)): ?>
        <tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted)">Записей нет</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
    <div style="padding:12px 20px;display:flex;gap:8px;flex-wrap:wrap">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a href="?q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filterStatus); ?>&page=<?php echo $p; ?>"
           class="adm-btn adm-btn--sm <?php echo $p === $page ? 'adm-btn--primary' : 'adm-btn--ghost'; ?>">
            <?php echo $p; ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
