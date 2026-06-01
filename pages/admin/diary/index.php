<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$db = Database::getConnection();

$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 30;
$offset = ($page - 1) * $limit;

$where  = $search ? "WHERE u.email LIKE :q" : "";
$params = $search ? ['q' => '%' . $search . '%'] : [];

$countStmt = $db->prepare("SELECT COUNT(*) FROM diary_entries de JOIN users u ON u.id = de.user_id $where");
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
     $where
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

<form method="get" style="display:flex;gap:10px;margin-bottom:20px">
    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
           placeholder="Поиск по email..." class="adm-input" style="flex:1;max-width:320px">
    <button type="submit" class="adm-btn adm-btn--primary">Найти</button>
    <?php if ($search): ?><a href="/admin/diary/" class="adm-btn adm-btn--ghost">Сбросить</a><?php endif; ?>
</form>

<div class="adm-card">
    <div class="adm-card__head">
        <div class="adm-card__title">Записи дневника (<?php echo $total; ?>)</div>
    </div>
    <table class="adm-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Пользователь</th>
                <th>Сообщений</th>
                <th>Саммари</th>
                <th>Дата</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $e): ?>
        <tr>
            <td style="color:var(--muted)"><?php echo $e['id']; ?></td>
            <td>
                <a href="/admin/users/view.php?id=<?php echo $e['user_id']; ?>" style="color:var(--accent)">
                    <?php echo htmlspecialchars($e['email']); ?>
                </a>
            </td>
            <td style="color:var(--muted)"><?php echo $e['msg_count']; ?></td>
            <td style="max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted)">
                <?php echo htmlspecialchars(mb_substr($e['summary'] ?: '—', 0, 100)); ?>
            </td>
            <td style="color:var(--muted);white-space:nowrap"><?php echo date('d.m.Y H:i', strtotime($e['created_at'])); ?></td>
            <td><a href="/admin/diary/view.php?id=<?php echo $e['id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">Открыть →</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($entries)): ?>
        <tr><td colspan="6" style="text-align:center;padding:20px;color:var(--muted)">Записей нет</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
    <div style="padding:12px 20px;display:flex;gap:8px">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a href="?q=<?php echo urlencode($search); ?>&page=<?php echo $p; ?>"
           class="adm-btn adm-btn--sm <?php echo $p === $page ? 'adm-btn--primary' : 'adm-btn--ghost'; ?>">
            <?php echo $p; ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
