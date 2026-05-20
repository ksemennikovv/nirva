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

$stmt = $db->prepare("SELECT d.*, u.email, u.id AS user_id FROM diary_entries d JOIN users u ON u.id = d.user_id WHERE d.id = ?");
$stmt->execute([$id]);
$entry = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entry) { http_response_code(404); echo 'Запись не найдена'; exit; }

$msgStmt = $db->prepare("SELECT * FROM diary_messages WHERE diary_entry_id = ? ORDER BY id ASC");
$msgStmt->execute([$id]);
$messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Дневник #' . $id;
$activeNav = 'diary';
require dirname(__DIR__) . '/_layout.php';
?>

<div style="display:flex;gap:16px;align-items:center;margin-bottom:20px">
    <a href="/admin/diary/" class="adm-btn adm-btn--ghost adm-btn--sm">← Назад</a>
    <a href="/admin/users/view.php?id=<?php echo $entry['user_id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">👤 <?php echo htmlspecialchars($entry['email']); ?></a>
</div>

<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">Запись от <?php echo date('d.m.Y H:i', strtotime($entry['created_at'])); ?></div></div>
    <?php if ($entry['summary']): ?>
    <div style="padding:16px 20px;font-size:13px;line-height:1.6;color:var(--text)">
        <div style="color:var(--muted);font-size:11px;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px">Итог сессии</div>
        <?php echo nl2br(htmlspecialchars($entry['summary'])); ?>
    </div>
    <?php endif; ?>
</div>

<div class="adm-card">
    <div class="adm-card__head"><div class="adm-card__title">Диалог (<?php echo count($messages); ?> сообщений)</div></div>
    <div class="adm-chat-log">
    <?php foreach ($messages as $m): ?>
        <div class="adm-chat-msg adm-chat-msg--<?php echo $m['role'] === 'user' ? 'user' : 'ai'; ?>">
            <div class="adm-chat-msg__meta">
                <span class="adm-chat-msg__role"><?php echo $m['role'] === 'user' ? 'Пользователь' : 'AI'; ?></span>
                <span style="color:var(--muted);font-size:11px"><?php echo date('H:i:s', strtotime($m['created_at'])); ?></span>
            </div>
            <div class="adm-chat-msg__text"><?php echo nl2br(htmlspecialchars($m['content'] ?? $m['message'] ?? '')); ?></div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($messages)): ?>
    <div style="padding:24px;text-align:center;color:var(--muted)">Сообщений нет</div>
    <?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
