<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo 'Bad request'; exit; }

$db = Database::getConnection();

$stmt = $db->prepare(
    "SELECT de.*, u.id as user_id, u.email
     FROM diary_entries de
     JOIN users u ON u.id = de.user_id
     WHERE de.id = ?"
);
$stmt->execute([$id]);
$entry = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$entry) { http_response_code(404); echo 'Запись не найдена'; exit; }

// Сообщения
$msgStmt = $db->prepare("SELECT * FROM diary_messages WHERE diary_entry_id = ? ORDER BY id ASC");
$msgStmt->execute([$id]);
$messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

// Обновления профиля
$profileHistory  = [];
$profileMemories = [];
try {
    $phStmt = $db->prepare(
        "SELECT pph.*, pp.code, pp.label
         FROM profile_parameter_history pph
         JOIN profile_parameters pp ON pp.id = pph.parameter_id
         WHERE pph.user_id = ? AND pph.source_type = 'diary' AND pph.source_id = ?
         ORDER BY pph.created_at ASC"
    );
    $phStmt->execute([$entry['user_id'], $id]);
    $profileHistory = $phStmt->fetchAll(PDO::FETCH_ASSOC);

    $pmStmt = $db->prepare(
        "SELECT * FROM user_memories
         WHERE user_id = ? AND source_type = 'diary' AND source_id = ?
         ORDER BY created_at ASC"
    );
    $pmStmt->execute([$entry['user_id'], $id]);
    $profileMemories = $pmStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {}

function readPromptFile(string $root, string $name): string {
    $path = $root . '/prompts/' . $name;
    return file_exists($path) ? file_get_contents($path) : '[файл не найден: ' . $name . ']';
}

$pageTitle = 'Дневник #' . $id;
$activeNav = 'diary';
require dirname(__DIR__) . '/_layout.php';
?>

<div style="display:flex;gap:12px;align-items:center;margin-bottom:20px;flex-wrap:wrap">
    <a href="/admin/diary/" class="adm-btn adm-btn--ghost adm-btn--sm">← Все записи</a>
    <a href="/admin/users/view.php?id=<?php echo $entry['user_id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">👤 Профиль: <?php echo htmlspecialchars($entry['email']); ?></a>
</div>

<!-- Мета -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
    <div class="adm-card">
        <div class="adm-card__head"><div class="adm-card__title">Запись дневника #<?php echo $id; ?></div></div>
        <div style="padding:16px 20px;font-size:13px;display:flex;flex-direction:column;gap:7px">
            <div style="display:flex;gap:12px">
                <span style="color:var(--muted);width:120px">Пользователь</span>
                <a href="/admin/users/view.php?id=<?php echo $entry['user_id']; ?>" style="color:var(--accent)"><?php echo htmlspecialchars($entry['email']); ?></a>
            </div>
            <div style="display:flex;gap:12px">
                <span style="color:var(--muted);width:120px">Дата</span>
                <span><?php echo date('d.m.Y H:i', strtotime($entry['created_at'])); ?></span>
            </div>
            <div style="display:flex;gap:12px">
                <span style="color:var(--muted);width:120px">Сообщений</span>
                <span><?php echo count($messages); ?></span>
            </div>
        </div>
    </div>

    <div class="adm-card">
        <div class="adm-card__head"><div class="adm-card__title">AI Саммари записи</div></div>
        <div style="padding:16px 20px;font-size:13px;line-height:1.6">
            <?php if ($entry['summary']): ?>
            <?php echo nl2br(htmlspecialchars($entry['summary'])); ?>
            <?php else: ?>
            <span style="color:var(--muted)">Саммари ещё не сформировано (диалог не завершён)</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Промты дневника -->
<div class="adm-card" style="margin-bottom:20px">
    <details>
        <summary class="adm-card__head" style="cursor:pointer;list-style:none">
            <div class="adm-card__title">📄 Промты дневника</div>
        </summary>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:12px 20px 16px">
            <details>
                <summary style="font-size:12px;cursor:pointer;color:var(--muted);margin-bottom:6px">Проработка (diary-prompt-vent.txt)</summary>
                <pre style="font-size:10px;background:#f8fafc;border:1px solid var(--border);padding:10px;border-radius:4px;overflow:auto;max-height:300px;white-space:pre-wrap"><?php echo htmlspecialchars(readPromptFile($root, 'diary-prompt-vent.txt')); ?></pre>
            </details>
            <details>
                <summary style="font-size:12px;cursor:pointer;color:var(--muted);margin-bottom:6px">Рефлексия (diary-prompt-reflection.txt)</summary>
                <pre style="font-size:10px;background:#f8fafc;border:1px solid var(--border);padding:10px;border-radius:4px;overflow:auto;max-height:300px;white-space:pre-wrap"><?php echo htmlspecialchars(readPromptFile($root, 'diary-prompt-reflection.txt')); ?></pre>
            </details>
        </div>
    </details>
</div>

<!-- Диалог -->
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">💬 Диалог (<?php echo count($messages); ?> сообщений)</div></div>
    <div class="adm-chat-log">
    <?php foreach ($messages as $m):
        $isUser = $m['role'] === 'user';
    ?>
        <div class="adm-chat-msg adm-chat-msg--<?php echo $isUser ? 'user' : 'ai'; ?>">
            <div class="adm-chat-msg__meta">
                <span class="adm-chat-msg__role"><?php echo $isUser ? 'Пользователь' : 'AI'; ?></span>
                <span style="color:var(--muted);font-size:11px"><?php echo date('H:i:s', strtotime($m['created_at'])); ?></span>
            </div>
            <div class="adm-chat-msg__text"><?php echo nl2br(htmlspecialchars($m['content'])); ?></div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($messages)): ?>
    <div style="padding:24px;text-align:center;color:var(--muted)">Сообщений нет</div>
    <?php endif; ?>
    </div>
</div>

<!-- Что внесено в профиль -->
<?php if (!empty($profileHistory) || !empty($profileMemories)): ?>
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">🧬 Что внесено в профиль из этой записи</div></div>
    <div style="padding:12px 20px;display:flex;flex-direction:column;gap:6px">
    <?php foreach ($profileHistory as $h): ?>
        <div style="font-size:12px;padding:5px 8px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;display:flex;gap:10px;align-items:center">
            <span class="adm-badge adm-badge--<?php echo $h['event_type'] === 'added' ? 'active' : ($h['event_type'] === 'removed' ? 'cancelled' : 'pending'); ?>" style="font-size:10px"><?php echo $h['event_type']; ?></span>
            <span style="font-weight:600;min-width:180px"><?php echo htmlspecialchars($h['label']); ?></span>
            <span style="color:var(--muted);flex:1;font-size:11px"><?php $ed = json_decode($h['event_data'], true); echo htmlspecialchars(is_array($ed) ? json_encode($ed, JSON_UNESCAPED_UNICODE) : $h['event_data']); ?></span>
            <span style="color:var(--muted);font-size:11px;white-space:nowrap"><?php echo date('d.m H:i', strtotime($h['created_at'])); ?></span>
        </div>
    <?php endforeach; ?>
    <?php foreach ($profileMemories as $mem): ?>
        <div style="font-size:12px;padding:5px 8px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;display:flex;gap:10px">
            <span class="adm-badge adm-badge--none" style="font-size:10px">memory</span>
            <span style="flex:1"><?php echo htmlspecialchars($mem['content']); ?></span>
            <span style="color:var(--muted)">важность: <?php echo $mem['importance_score']; ?></span>
            <span style="color:var(--muted);font-size:11px"><?php echo date('d.m H:i', strtotime($mem['created_at'])); ?></span>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="adm-card" style="margin-bottom:20px;padding:20px">
    <p style="color:var(--muted);font-size:13px">Обновлений психоэмоционального профиля из этой записи нет<?php echo !$entry['summary'] ? ' — диалог ещё не завершён' : ''; ?>.</p>
</div>
<?php endif; ?>

<style>
.adm-chat-log { display:flex; flex-direction:column; gap:8px; padding:16px; background:var(--bg); }
</style>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
