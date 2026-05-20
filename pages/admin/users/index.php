<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/repositories/UserRepository.php';

$userRepo = new UserRepository();
$search   = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 25;
$offset   = ($page - 1) * $perPage;

$users = $userRepo->getAll($perPage, $offset, $search);
$total = $userRepo->countAll($search);
$pages = (int)ceil($total / $perPage);

$pageTitle = 'Пользователи';
$activeNav = 'users';
require dirname(__DIR__) . '/_layout.php';
?>

<div class="adm-card">
    <div class="adm-card__head">
        <div class="adm-card__title">Всего: <?php echo $total; ?></div>
        <form class="adm-search" method="get">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Поиск по email...">
            <button type="submit" class="adm-btn adm-btn--primary adm-btn--sm">Найти</button>
            <?php if ($search): ?><a href="/admin/users/" class="adm-btn adm-btn--ghost adm-btn--sm">Сбросить</a><?php endif; ?>
        </form>
    </div>

    <table class="adm-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Email</th>
                <th>Роль</th>
                <th>Тариф</th>
                <th>Разборов</th>
                <th>Подписка до</th>
                <th>Регистрация</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u):
            $plan    = $u['plan'] ?? null;
            $planCls = $plan ? 'adm-badge--' . $plan : 'adm-badge--none';
            $subExp  = $u['expires_at'] ? date('d.m.Y', strtotime($u['expires_at'])) : '—';
            $subCls  = $u['sub_status'] === 'active' ? 'adm-badge--active' : 'adm-badge--none';
            $used    = $u['analyses_used'] ?? 0;
            $limit   = $u['analyses_per_month'] ?? 0;
        ?>
        <tr>
            <td style="color:var(--muted)"><?php echo $u['id']; ?></td>
            <td><a href="/admin/users/view.php?id=<?php echo $u['id']; ?>" style="color:var(--accent);text-decoration:none;font-weight:500"><?php echo htmlspecialchars($u['email']); ?></a></td>
            <td><?php if ($u['role'] === 'admin'): ?><span class="adm-badge adm-badge--admin">admin</span><?php endif; ?></td>
            <td><span class="adm-badge <?php echo $planCls; ?>"><?php echo $plan ?? 'нет'; ?></span></td>
            <td style="color:var(--muted)"><?php echo $limit ? "$used / $limit" : '—'; ?></td>
            <td style="color:var(--muted)"><?php echo $subExp; ?></td>
            <td style="color:var(--muted)"><?php echo date('d.m.Y', strtotime($u['created_at'])); ?></td>
            <td>
                <a href="/admin/users/view.php?id=<?php echo $u['id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">Открыть</a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
        <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--muted)">Пользователи не найдены</td></tr>
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
