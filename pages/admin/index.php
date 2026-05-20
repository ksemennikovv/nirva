<?php
session_start();
$root = dirname(__DIR__, 2);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/config/business.php';
require_once $root . '/src/middleware/admin.php';

$db = Database::getConnection();

// ── Метрики ───────────────────────────────────────────────────────────────
$totalUsers    = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeSubs    = (int)$db->query("SELECT COUNT(*) FROM subscriptions WHERE status = 'active'")->fetchColumn();
$analysesToday = (int)$db->query("SELECT COUNT(*) FROM analysis_sessions WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$analysesDone  = (int)$db->query("SELECT COUNT(*) FROM analysis_sessions WHERE status = 'completed'")->fetchColumn();
$medPending    = (int)$db->query("SELECT COUNT(*) FROM meditations WHERE generation_status IN ('pending','generating')")->fetchColumn();
$medFailed     = (int)$db->query("SELECT COUNT(*) FROM meditations WHERE generation_status = 'failed'")->fetchColumn();
$diaryTotal    = (int)$db->query("SELECT COUNT(*) FROM diary_entries")->fetchColumn();
$pendingReview = (int)$db->query("SELECT COUNT(*) FROM messages WHERE role='assistant' AND review_status='pending_review'")->fetchColumn();

// ── Последние пользователи ────────────────────────────────────────────────
$lastUsers = $db->query("
    SELECT u.id, u.email, u.role, u.created_at, s.plan
    FROM users u
    LEFT JOIN subscriptions s ON s.user_id = u.id AND s.status = 'active'
    ORDER BY u.id DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// ── Последние разборы ─────────────────────────────────────────────────────
$lastAnalyses = $db->query("
    SELECT a.id, a.topic, a.status, a.created_at, u.email
    FROM analysis_sessions a
    JOIN users u ON u.id = a.user_id
    ORDER BY a.id DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Дашборд';
$activeNav = 'dashboard';
require __DIR__ . '/_layout.php';
?>

<?php $supervisorActive = BusinessConfig::isSupervisorMode(); ?>
<div style="display:flex;gap:12px;align-items:center;padding:14px 18px;background:<?php echo $supervisorActive ? '#dcfce7' : '#f1f5f9'; ?>;border:1px solid <?php echo $supervisorActive ? '#86efac' : '#cbd5e1'; ?>;border-radius:8px;margin-bottom:20px">
    <span style="font-size:20px"><?php echo $supervisorActive ? '⚡' : '🔇'; ?></span>
    <div>
        <div style="font-weight:700;color:<?php echo $supervisorActive ? '#15803d' : '#475569'; ?>">
            Режим отладки психолога: <span><?php echo $supervisorActive ? 'ВКЛЮЧЁН' : 'ВЫКЛЮЧЕН'; ?></span>
        </div>
        <div style="font-size:13px;color:<?php echo $supervisorActive ? '#166534' : '#64748b'; ?>">
            <?php if ($supervisorActive): ?>
                Все ответы ИИ ждут вашего одобрения перед отправкой пользователю
            <?php else: ?>
                Сообщения от ИИ доставляются пользователям автоматически
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;gap:8px;margin-left:auto;align-items:center;flex-shrink:0">
        <?php if ($supervisorActive && $pendingReview > 0): ?>
        <a href="/admin/analyses/supervise.php" class="adm-btn adm-btn--primary" style="white-space:nowrap">
            ⏳ Ожидают: <?php echo $pendingReview; ?>
        </a>
        <?php elseif ($supervisorActive): ?>
        <a href="/admin/analyses/supervise.php" class="adm-btn adm-btn--ghost adm-btn--sm">Открыть надзор</a>
        <?php endif; ?>
        <form method="post" action="/admin/api/toggle-supervisor.php" style="margin:0">
            <button type="submit" class="adm-btn <?php echo $supervisorActive ? 'adm-btn--danger' : 'adm-btn--primary'; ?> adm-btn--sm"
                    onclick="return confirm('<?php echo $supervisorActive ? 'Выключить режим отладки? ИИ будет отвечать автоматически.' : 'Включить режим отладки? Все ответы ИИ будут ждать вашего одобрения.'; ?>')">
                <?php echo $supervisorActive ? 'Выключить' : 'Включить'; ?>
            </button>
        </form>
    </div>
</div>
<?php if (!$supervisorActive && $pendingReview > 0): ?>
<div style="padding:12px 18px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;margin-bottom:20px;font-size:13px;color:#92400e">
    ⚠️ Есть <?php echo $pendingReview; ?> сообщений с <code>pending_review</code> — они не будут доставлены пока режим выключен.
    <a href="/admin/analyses/supervise.php" style="color:var(--accent);margin-left:8px">Посмотреть</a>
</div>
<?php endif; ?>

<div class="adm-metrics">
    <div class="adm-metric">
        <div class="adm-metric__label">Пользователей</div>
        <div class="adm-metric__value"><?php echo $totalUsers; ?></div>
    </div>
    <div class="adm-metric">
        <div class="adm-metric__label">Активных подписок</div>
        <div class="adm-metric__value"><?php echo $activeSubs; ?></div>
    </div>
    <div class="adm-metric">
        <div class="adm-metric__label">Разборов сегодня</div>
        <div class="adm-metric__value"><?php echo $analysesToday; ?></div>
    </div>
    <div class="adm-metric">
        <div class="adm-metric__label">Завершённых разборов</div>
        <div class="adm-metric__value"><?php echo $analysesDone; ?></div>
    </div>
    <div class="adm-metric">
        <div class="adm-metric__label">Медитаций в очереди</div>
        <div class="adm-metric__value" style="<?php echo $medPending ? 'color:var(--warning)' : ''; ?>"><?php echo $medPending; ?></div>
    </div>
    <div class="adm-metric">
        <div class="adm-metric__label">Медитаций с ошибкой</div>
        <div class="adm-metric__value" style="<?php echo $medFailed ? 'color:var(--danger)' : ''; ?>"><?php echo $medFailed; ?></div>
    </div>
    <div class="adm-metric">
        <div class="adm-metric__label">Записей дневника</div>
        <div class="adm-metric__value"><?php echo $diaryTotal; ?></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

    <div class="adm-card">
        <div class="adm-card__head">
            <div class="adm-card__title">Последние пользователи</div>
            <a href="/admin/users/" class="adm-btn adm-btn--ghost adm-btn--sm">Все →</a>
        </div>
        <table class="adm-table">
            <thead><tr><th>Email</th><th>План</th><th>Дата</th></tr></thead>
            <tbody>
            <?php foreach ($lastUsers as $u): ?>
            <tr>
                <td><a href="/admin/users/view.php?id=<?php echo $u['id']; ?>" style="color:var(--accent);text-decoration:none"><?php echo htmlspecialchars($u['email']); ?></a></td>
                <td><?php
                    $plan = $u['plan'] ?? null;
                    $cls  = $plan ? 'adm-badge--' . $plan : 'adm-badge--none';
                    echo '<span class="adm-badge ' . $cls . '">' . ($plan ?? 'нет') . '</span>';
                ?></td>
                <td style="color:var(--muted)"><?php echo date('d.m.Y', strtotime($u['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="adm-card">
        <div class="adm-card__head">
            <div class="adm-card__title">Последние разборы</div>
            <a href="/admin/analyses/" class="adm-btn adm-btn--ghost adm-btn--sm">Все →</a>
        </div>
        <table class="adm-table">
            <thead><tr><th>Тема</th><th>Статус</th><th>Пользователь</th></tr></thead>
            <tbody>
            <?php foreach ($lastAnalyses as $a): ?>
            <tr>
                <td><a href="/admin/analyses/view.php?id=<?php echo $a['id']; ?>" style="color:var(--accent);text-decoration:none"><?php echo htmlspecialchars($a['topic'] ?: '#' . $a['id']); ?></a></td>
                <td><?php
                    $statusMap = ['created'=>'adm-badge--pending','active'=>'adm-badge--generating','completed'=>'adm-badge--active','abandoned'=>'adm-badge--cancelled'];
                    $cls = $statusMap[$a['status']] ?? 'adm-badge--none';
                    echo '<span class="adm-badge ' . $cls . '">' . htmlspecialchars($a['status']) . '</span>';
                ?></td>
                <td style="color:var(--muted);font-size:12px"><?php echo htmlspecialchars($a['email']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

<?php require __DIR__ . '/_layout_end.php'; ?>
