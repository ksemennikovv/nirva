<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/config/business.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/repositories/MessageRepository.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo 'Bad request'; exit; }

$debugView = isset($_GET['debug']);

$db = Database::getConnection();

$stmt = $db->prepare("SELECT a.*, u.email FROM analysis_sessions a JOIN users u ON u.id = a.user_id WHERE a.id = ?");
$stmt->execute([$id]);
$analysis = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$analysis) { http_response_code(404); echo 'Разбор не найден'; exit; }

$msgRepo = new MessageRepository();
// В режиме отладки — все сообщения включая rejected + коррекции
try {
    $messages = $debugView
        ? $msgRepo->getAllWithReviewStatus($id)
        : $msgRepo->getApprovedMessages($id);
} catch (\PDOException $e) {
    // Миграция 006 ещё не выполнена — fallback на обычные сообщения
    $stmt2 = $db->prepare("SELECT * FROM messages WHERE analysis_session_id = ? ORDER BY id ASC");
    $stmt2->execute([$id]);
    $messages = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}

$medStmt = $db->prepare("SELECT id, title, description, generation_status, full_audio_url, created_at FROM meditations WHERE analysis_id = ? ORDER BY id");
$medStmt->execute([$id]);
$meditations = $medStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Разбор #' . $id;
$activeNav = 'analyses';
require dirname(__DIR__) . '/_layout.php';
?>

<div style="display:flex;gap:16px;align-items:center;margin-bottom:20px;flex-wrap:wrap">
    <a href="/admin/analyses/" class="adm-btn adm-btn--ghost adm-btn--sm">← Назад</a>
    <a href="/admin/users/view.php?id=<?php echo $analysis['user_id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">👤 <?php echo htmlspecialchars($analysis['email']); ?></a>
    <?php if ($debugView): ?>
    <a href="/admin/analyses/view.php?id=<?php echo $id; ?>" class="adm-btn adm-btn--primary adm-btn--sm">🔧 Вид отладки ВКЛ</a>
    <?php else: ?>
    <a href="/admin/analyses/view.php?id=<?php echo $id; ?>&debug=1" class="adm-btn adm-btn--ghost adm-btn--sm">🔧 Вид отладки</a>
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
    <div class="adm-card">
        <div class="adm-card__head"><div class="adm-card__title">Метаданные</div></div>
        <div style="padding:16px 20px;font-size:13px;display:flex;flex-direction:column;gap:8px">
            <?php
            $meta = [
                'ID'        => $analysis['id'],
                'Статус'    => '<span class="adm-badge adm-badge--' . (['created'=>'pending','active'=>'generating','completed'=>'active','abandoned'=>'cancelled'][$analysis['status']] ?? 'none') . '">' . $analysis['status'] . '</span>',
                'Тема'      => htmlspecialchars($analysis['topic'] ?? '—'),
                'Практика'  => htmlspecialchars($analysis['selected_practice'] ?? '—'),
                'Задача'    => htmlspecialchars($analysis['personal_task'] ?? '—'),
                'Создан'    => date('d.m.Y H:i', strtotime($analysis['created_at'])),
                'Завершён'  => $analysis['completed_at'] ? date('d.m.Y H:i', strtotime($analysis['completed_at'])) : '—',
            ];
            foreach ($meta as $k => $v):
            ?>
            <div style="display:flex;gap:12px">
                <span style="color:var(--muted);width:100px;flex-shrink:0"><?php echo $k; ?></span>
                <span><?php echo $v; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="adm-card">
        <div class="adm-card__head"><div class="adm-card__title">Итоги разбора</div></div>
        <div style="padding:16px 20px;font-size:13px;display:flex;flex-direction:column;gap:12px">
            <?php if ($analysis['analysis_summary']): ?>
            <div>
                <div style="color:var(--muted);margin-bottom:4px">Итог анализа</div>
                <div style="line-height:1.5"><?php echo nl2br(htmlspecialchars($analysis['analysis_summary'])); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($analysis['reflection_summary']): ?>
            <div>
                <div style="color:var(--muted);margin-bottom:4px">Итог рефлексии</div>
                <div style="line-height:1.5"><?php echo nl2br(htmlspecialchars($analysis['reflection_summary'])); ?></div>
            </div>
            <?php endif; ?>
            <?php if (!$analysis['analysis_summary'] && !$analysis['reflection_summary']): ?>
            <span style="color:var(--muted)">Итоги ещё не сформированы</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Диалог -->
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">Диалог (<?php echo count($messages); ?> сообщений)</div></div>
    <div class="adm-chat-log">
    <?php foreach ($messages as $m):
        $reviewStatus = $m['review_status'] ?? 'approved';
        $isUser       = $m['role'] === 'user';
        $isRejected   = $reviewStatus === 'rejected';
        $isPending    = $reviewStatus === 'pending_review';
        $displayText  = ($m['reviewed_content'] ?? null) ?: ($m['content'] ?? '');
    ?>
        <?php if ($m['supervisor_instruction'] ?? null): ?>
        <!-- Плашка коррекции психолога (показывается перед rejected сообщением) -->
        <div style="align-self:stretch;padding:8px 14px;background:#ede9fe;border:1px solid #c4b5fd;border-radius:6px;font-size:12px;line-height:1.5">
            <span style="font-weight:700;color:#5b21b6">🔧 SUPERVISOR:</span>
            <span style="color:#4c1d95"><?php echo htmlspecialchars($m['supervisor_instruction']); ?></span>
        </div>
        <?php endif; ?>

        <div class="adm-chat-msg adm-chat-msg--<?php echo $isUser ? 'user' : 'ai'; ?>"
             style="<?php echo $isRejected ? 'opacity:.5' : ''; ?>">
            <div class="adm-chat-msg__meta">
                <span class="adm-chat-msg__role"><?php echo $isUser ? 'Пользователь' : 'AI'; ?></span>
                <?php if ($isRejected): ?>
                <span class="adm-badge adm-badge--cancelled" style="font-size:10px;padding:2px 6px">REJECTED</span>
                <?php elseif ($isPending): ?>
                <span class="adm-badge adm-badge--pending" style="font-size:10px;padding:2px 6px">PENDING</span>
                <?php elseif (!$isUser && $debugView): ?>
                <span class="adm-badge adm-badge--active" style="font-size:10px;padding:2px 6px">APPROVED</span>
                <?php endif; ?>
                <?php if ($m['reviewed_content'] ?? null): ?>
                <span style="color:#6d28d9;font-size:10px">✎ отредактировано психологом</span>
                <?php endif; ?>
                <span style="color:var(--muted);font-size:11px"><?php echo date('H:i:s', strtotime($m['created_at'])); ?></span>
            </div>
            <div class="adm-chat-msg__text" style="<?php echo $isRejected ? 'text-decoration:line-through;opacity:.7' : ''; ?>"><?php echo nl2br(htmlspecialchars($displayText)); ?></div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($messages)): ?>
    <div style="padding:24px;text-align:center;color:var(--muted)">Сообщений нет</div>
    <?php endif; ?>
    </div>
</div>

<!-- Медитации -->
<?php if (!empty($meditations)): ?>
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">Медитации (<?php echo count($meditations); ?>)</div></div>
    <table class="adm-table">
        <thead><tr><th>ID</th><th>Название</th><th>Статус</th><th>Аудио</th><th>Дата</th></tr></thead>
        <tbody>
        <?php foreach ($meditations as $med):
            $msCls = ['ready'=>'active','pending'=>'pending','failed'=>'cancelled','generating'=>'generating'][$med['generation_status']] ?? 'none';
        ?>
        <tr>
            <td style="color:var(--muted)"><?php echo $med['id']; ?></td>
            <td><?php echo htmlspecialchars($med['title'] ?? '—'); ?></td>
            <td><span class="adm-badge adm-badge--<?php echo $msCls; ?>"><?php echo $med['generation_status']; ?></span></td>
            <td><?php if ($med['full_audio_url']): ?><a href="<?php echo htmlspecialchars($med['full_audio_url']); ?>" target="_blank" style="color:var(--accent)">🎧 Слушать</a><?php else: ?>—<?php endif; ?></td>
            <td style="color:var(--muted)"><?php echo date('d.m.Y H:i', strtotime($med['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Удалить разбор -->
<div style="margin-top:24px;padding:16px;background:#fff5f5;border:1px solid #fecaca;border-radius:8px">
    <div style="font-weight:600;margin-bottom:8px;color:var(--danger)">Удалить разбор</div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:12px">Удалит разбор, все сообщения и медитации. Действие необратимо.</p>
    <form method="post" action="/admin/analyses/api/delete.php" onsubmit="return confirm('Удалить разбор #<?php echo $id; ?>?')">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="redirect" value="/admin/analyses/">
        <button type="submit" class="adm-btn adm-btn--danger">Удалить разбор</button>
    </form>
</div>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
