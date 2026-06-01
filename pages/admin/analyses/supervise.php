<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$db = Database::getConnection();

// Все сессии с pending сообщениями (для списка)
$sessionsStmt = $db->query("
    SELECT a.id AS session_id, a.topic, a.user_id,
           COALESCE(u.email, '—') AS email,
           COUNT(m.id) AS pending_count,
           MIN(m.created_at) AS oldest_pending
    FROM messages m
    JOIN analysis_sessions a ON a.id = m.analysis_session_id
    LEFT JOIN users u ON u.id = a.user_id
    WHERE m.role = 'assistant' AND m.review_status = 'pending_review'
    GROUP BY a.id
    ORDER BY oldest_pending ASC
");
$sessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Выбранная сессия для детального просмотра
$focusSessionId = (int)($_GET['session_id'] ?? ($sessions[0]['session_id'] ?? 0));

// Сообщения выбранной сессии — только СТАРЕЙШЕЕ pending (одно за раз)
$pending      = [];
$pendingTotal = 0;
if ($focusSessionId) {
    $totalStmt = $db->prepare("
        SELECT COUNT(*) FROM messages
        WHERE analysis_session_id = ? AND role = 'assistant' AND review_status = 'pending_review'
    ");
    $totalStmt->execute([$focusSessionId]);
    $pendingTotal = (int)$totalStmt->fetchColumn();

    $pendingStmt = $db->prepare("
        SELECT m.id AS msg_id, m.content, m.created_at AS msg_time,
               a.id AS session_id, a.topic, a.user_id,
               u.email
        FROM messages m
        JOIN analysis_sessions a ON a.id = m.analysis_session_id
        LEFT JOIN users u ON u.id = a.user_id
        WHERE m.role = 'assistant' AND m.review_status = 'pending_review'
          AND a.id = ?
        ORDER BY m.created_at ASC
        LIMIT 1
    ");
    $pendingStmt->execute([$focusSessionId]);
    $pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Надзор за разборами';
$activeNav = 'supervise';
require dirname(__DIR__) . '/_layout.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <?php if (!BusinessConfig::isSupervisorMode()): ?>
    <div style="padding:10px 16px;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;font-size:13px;color:#92400e">
        ⚠️ Supervisor Mode <b>отключён</b>. Сообщения доставляются пользователям автоматически.
    </div>
    <?php else: ?>
    <div style="padding:10px 16px;background:#dcfce7;border:1px solid #86efac;border-radius:6px;font-size:13px;color:#15803d">
        ⚡ Supervisor Mode <b>активен</b> — все ответы ИИ ждут вашего одобрения.
    </div>
    <?php endif; ?>
    <a href="/admin/analyses/supervise.php" class="adm-btn adm-btn--ghost adm-btn--sm">↺ Обновить</a>
</div>

<div style="display:grid;grid-template-columns:280px 1fr;gap:20px;align-items:start">

<!-- ── Список разборов, требующих модерации ─────────────────────────────── -->
<div class="adm-card" style="position:sticky;top:20px">
    <div class="adm-card__head"><div class="adm-card__title" id="queue-title">Очередь (<?php echo count($sessions); ?>)</div></div>
    <div id="session-list">
    <?php if (empty($sessions)): ?>
    <div style="padding:20px;text-align:center;color:var(--muted);font-size:13px">✓ Очередь пуста</div>
    <?php else: ?>
    <?php foreach ($sessions as $s):
        $isActive = $s['session_id'] === $focusSessionId;
    ?>
    <a href="/admin/analyses/supervise.php?session_id=<?php echo $s['session_id']; ?>"
       style="display:block;padding:12px 16px;border-bottom:1px solid var(--border);text-decoration:none;background:<?php echo $isActive ? '#fffbeb' : ''; ?>;border-left:<?php echo $isActive ? '3px solid var(--warning)' : '3px solid transparent'; ?>">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
            <span style="font-size:12px;font-weight:600;color:var(--text)"><?php echo htmlspecialchars(mb_substr($s['email'], 0, 22)); ?></span>
            <span class="adm-badge adm-badge--pending" style="font-size:10px;padding:2px 6px"><?php echo $s['pending_count']; ?></span>
        </div>
        <div style="font-size:11px;color:var(--muted)">#<?php echo $s['session_id']; ?> <?php echo $s['topic'] ? '· ' . htmlspecialchars(mb_substr($s['topic'], 0, 24)) : '—'; ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px"><?php echo date('d.m H:i', strtotime($s['oldest_pending'])); ?></div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>

<!-- ── Детальный вид выбранной сессии ────────────────────────────────────── -->
<div id="pending-container">

<?php if (empty($sessions)): ?>
<div style="padding:60px;text-align:center;color:var(--muted);background:var(--surface);border-radius:var(--radius);border:1px solid var(--border)">
    ✓ Нет разборов, ожидающих проверки
</div>
<?php elseif (empty($pending)): ?>
<div style="padding:40px;text-align:center;color:var(--muted);background:var(--surface);border-radius:var(--radius);border:1px solid var(--border)">
    Выберите разбор из списка слева
</div>
<?php endif; ?>

<?php foreach ($pending as $item):
    $ctxStmt = $db->prepare("
        SELECT role, COALESCE(reviewed_content, content) AS content FROM messages
        WHERE analysis_session_id = ?
          AND (role='user' OR review_status='approved' OR review_status IS NULL)
        ORDER BY id DESC LIMIT 6
    ");
    $ctxStmt->execute([$item['session_id']]);
    $context = array_reverse($ctxStmt->fetchAll(PDO::FETCH_ASSOC));
?>
<div class="adm-card" style="margin-bottom:20px;border-left:4px solid var(--warning)">
    <div class="adm-card__head" style="background:#fffbeb">
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <span class="adm-badge adm-badge--pending">⏳ ОЖИДАЕТ</span>
            <a href="/admin/users/view.php?id=<?php echo $item['user_id']; ?>" style="color:var(--accent);text-decoration:none;font-size:13px"><?php echo htmlspecialchars($item['email']); ?></a>
            <span style="color:var(--muted);font-size:12px">Разбор #<?php echo $item['session_id']; ?><?php echo $item['topic'] ? ' · ' . htmlspecialchars($item['topic']) : ''; ?></span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-shrink:0">
            <span style="color:var(--muted);font-size:12px"><?php echo date('H:i:s', strtotime($item['msg_time'])); ?></span>
            <a href="/admin/analyses/view.php?id=<?php echo $item['session_id']; ?>&debug=1" class="adm-btn adm-btn--ghost adm-btn--sm" target="_blank">🔧 Весь разбор</a>
        </div>
    </div>

    <!-- Индикатор очереди -->
    <?php if ($pendingTotal > 1): ?>
    <div style="padding:6px 20px;background:#fef3c7;border-bottom:1px solid #fcd34d;font-size:12px;color:#92400e;font-weight:600">
        📋 Ответ 1 из <?php echo $pendingTotal; ?> — одобрите по очереди, следующий появится автоматически
    </div>
    <?php endif; ?>

    <!-- Контекст диалога -->
    <div style="padding:12px 20px;background:#fafbfc;border-bottom:1px solid var(--border)">
        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Контекст переписки</div>
        <div style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($context as $ctx): ?>
            <div style="display:flex;gap:10px;align-items:flex-start">
                <span style="font-size:11px;color:var(--muted);width:80px;flex-shrink:0;padding-top:2px"><?php echo $ctx['role'] === 'user' ? '👤 Клиент' : '🤖 ИИ ✓'; ?></span>
                <span style="font-size:13px;line-height:1.5;color:<?php echo $ctx['role'] === 'user' ? 'var(--text)' : '#15803d'; ?>"><?php $ctxText = (string)($ctx['content'] ?? ''); echo nl2br(htmlspecialchars(mb_substr($ctxText, 0, 400))); ?><?php echo mb_strlen($ctxText) > 400 ? '...' : ''; ?></span>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Ответ ИИ на проверке -->
    <div style="padding:16px 20px">
        <div style="font-size:11px;color:var(--warning);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;font-weight:700">⏳ Ответ ИИ — ожидает решения</div>

        <form method="post" action="/admin/analyses/api/approve-message.php">
            <input type="hidden" name="id" value="<?php echo $item['msg_id']; ?>">
            <input type="hidden" name="redirect" value="/admin/analyses/supervise.php?session_id=<?php echo $item['session_id']; ?>">
            <textarea name="content" rows="5" style="width:100%;padding:10px;border:2px solid var(--border);border-radius:6px;font-size:13px;line-height:1.6;resize:vertical;font-family:inherit"><?php echo htmlspecialchars((string)($item['content'] ?? '')); ?></textarea>
            <div style="margin-top:10px;display:flex;gap:10px;align-items:center">
                <button type="submit" class="adm-btn adm-btn--primary">✓ Отправить пользователю</button>
                <span style="font-size:12px;color:var(--muted)">можно отредактировать текст перед отправкой</span>
            </div>
        </form>

        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
            <div style="font-size:12px;font-weight:600;color:var(--danger);margin-bottom:8px">↺ Переделать — дать инструкцию ИИ</div>
            <form method="post" action="/admin/analyses/api/reject-and-retry.php">
                <input type="hidden" name="id" value="<?php echo $item['msg_id']; ?>">
                <input type="hidden" name="session_id" value="<?php echo $item['session_id']; ?>">
                <input type="hidden" name="redirect" value="/admin/analyses/supervise.php?session_id=<?php echo $item['session_id']; ?>">
                <textarea name="instruction" rows="3" required placeholder="Например: ты ушёл в сторону. Узнай про точку А конкретнее — что происходит прямо сейчас?" style="width:100%;padding:8px;border:1px solid #fca5a5;border-radius:6px;font-size:13px;resize:vertical;font-family:inherit"></textarea>
                <button type="submit" class="adm-btn adm-btn--danger" style="margin-top:8px">↺ Отправить ИИ на доработку</button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

</div><!-- /pending-container -->
</div><!-- /grid -->

<script>
(function () {
    const FOCUS_SESSION = <?php echo $focusSessionId ?: 'null'; ?>;
    let knownLatestMsgId = null; // будет установлен после первого ответа
    let userTyping = false;
    let typingTimer = null;

    // Отслеживаем ввод в формах — не мешаем, пока печатает
    document.addEventListener('focusin',  e => { if (e.target.matches('textarea,input')) { userTyping = true; clearTimeout(typingTimer); } });
    document.addEventListener('focusout', e => { if (e.target.matches('textarea,input')) { typingTimer = setTimeout(() => { userTyping = false; }, 8000); } });
    document.addEventListener('input',    e => { if (e.target.matches('textarea,input')) { userTyping = true; clearTimeout(typingTimer); } });

    function renderSessionList(sessions, focusId) {
        const container = document.getElementById('session-list');
        if (!container) return;
        if (!sessions.length) {
            container.innerHTML = '<div style="padding:20px;text-align:center;color:var(--muted);font-size:13px">✓ Очередь пуста</div>';
            return;
        }
        container.innerHTML = sessions.map(s => {
            const isActive = s.session_id == focusId;
            const email = s.email.length > 22 ? s.email.slice(0, 22) + '…' : s.email;
            const topic = s.topic ? ' · ' + (s.topic.length > 24 ? s.topic.slice(0, 24) + '…' : s.topic) : '—';
            const dt = new Date(s.oldest_pending.replace(' ', 'T'));
            const time = dt.toLocaleString('ru', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
            return `<a href="/admin/analyses/supervise.php?session_id=${s.session_id}"
                style="display:block;padding:12px 16px;border-bottom:1px solid var(--border);text-decoration:none;
                       background:${isActive ? '#fffbeb' : ''};
                       border-left:${isActive ? '3px solid var(--warning)' : '3px solid transparent'}">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                    <span style="font-size:12px;font-weight:600;color:var(--text)">${email}</span>
                    <span class="adm-badge adm-badge--pending" style="font-size:10px;padding:2px 6px">${s.pending_count}</span>
                </div>
                <div style="font-size:11px;color:var(--muted)">#${s.session_id}${topic}</div>
                <div style="font-size:11px;color:var(--muted);margin-top:2px">${time}</div>
            </a>`;
        }).join('');

        // Заголовок с общим счётчиком
        const title = document.getElementById('queue-title');
        if (title) title.textContent = 'Очередь (' + sessions.length + ')';
    }

    function showNewMsgBanner() {
        if (document.getElementById('new-msg-banner')) return;
        const banner = document.createElement('div');
        banner.id = 'new-msg-banner';
        banner.style.cssText = 'padding:12px 18px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;font-size:13px;color:#92400e';
        banner.innerHTML = '⚡ Получен новый ответ ИИ для этого разбора — <a href="" style="color:var(--accent);font-weight:600" onclick="location.reload();return false">Загрузить</a>';
        const container = document.getElementById('pending-container');
        if (container) container.prepend(banner);
    }

    async function poll() {
        try {
            const url = '/admin/analyses/api/pending-queue.php' + (FOCUS_SESSION ? '?session_id=' + FOCUS_SESSION : '');
            const res  = await fetch(url);
            const data = await res.json();

            // Обновляем список сессий всегда
            renderSessionList(data.sessions || [], FOCUS_SESSION);

            // Если появился новый pending для текущей сессии — показываем баннер
            if (FOCUS_SESSION && data.latest_msg_id !== null) {
                if (knownLatestMsgId === null) {
                    knownLatestMsgId = data.latest_msg_id; // запоминаем при первом опросе
                } else if (data.latest_msg_id !== knownLatestMsgId) {
                    knownLatestMsgId = data.latest_msg_id;
                    if (!userTyping) {
                        location.reload(); // нет активного ввода — перезагрузим тихо
                    } else {
                        showNewMsgBanner(); // печатает — покажем баннер
                    }
                }
            }
        } catch (_) {}
    }

    // Первый опрос сразу, затем каждые 4 секунды
    poll();
    setInterval(poll, 4000);
})();
</script>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
