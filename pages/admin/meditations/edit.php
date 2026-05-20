<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo 'Bad request'; exit; }

$db   = Database::getConnection();
$stmt = $db->prepare("SELECT m.*, u.email, c.name AS category_name
    FROM meditations m
    LEFT JOIN users u ON u.id = m.user_id
    LEFT JOIN meditation_categories c ON c.id = m.category_id
    WHERE m.id = ?");
$stmt->execute([$id]);
$med = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$med) { http_response_code(404); echo 'Медитация не найдена'; exit; }

$cats = $db->query("SELECT id, name FROM meditation_categories WHERE user_id IS NULL ORDER BY sort_order, name")
           ->fetchAll(PDO::FETCH_ASSOC);

$flash = $_GET['saved'] ?? '';

$pageTitle = 'Медитация #' . $id;
$activeNav = 'meditations';
require dirname(__DIR__) . '/_layout.php';
?>

<?php if ($flash === '1'): ?><div class="adm-alert adm-alert--success">Сохранено.</div><?php endif; ?>

<div style="display:flex;gap:12px;margin-bottom:20px">
    <a href="/admin/meditations/" class="adm-btn adm-btn--ghost adm-btn--sm">← Медитации</a>
    <?php if ($med['analysis_id']): ?>
    <a href="/admin/analyses/view.php?id=<?php echo $med['analysis_id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">📋 Разбор #<?php echo $med['analysis_id']; ?></a>
    <?php endif; ?>
    <?php if ($med['user_id']): ?>
    <a href="/admin/users/view.php?id=<?php echo $med['user_id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">👤 <?php echo htmlspecialchars($med['email'] ?? ''); ?></a>
    <?php endif; ?>
</div>

<div class="adm-card">
    <div class="adm-card__head"><div class="adm-card__title">Редактировать медитацию #<?php echo $id; ?></div></div>
    <div style="padding:20px">
        <form class="adm-form" method="post" action="/admin/meditations/api/save-edit.php">
            <input type="hidden" name="id" value="<?php echo $id; ?>">

            <div class="adm-form-row">
                <div class="adm-field">
                    <label>Название</label>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($med['title'] ?? ''); ?>" placeholder="Название медитации">
                </div>
                <div class="adm-field">
                    <label>Тема</label>
                    <input type="text" name="topic" value="<?php echo htmlspecialchars($med['topic'] ?? ''); ?>">
                </div>
            </div>

            <div class="adm-field">
                <label>Описание</label>
                <textarea name="description" rows="3" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;font-size:13px;resize:vertical"><?php echo htmlspecialchars($med['description'] ?? ''); ?></textarea>
            </div>

            <div class="adm-field">
                <label>Персональный контекст</label>
                <textarea name="personal_context" rows="3" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;font-size:13px;resize:vertical"><?php echo htmlspecialchars($med['personal_context'] ?? ''); ?></textarea>
            </div>

            <div class="adm-form-row">
                <div class="adm-field">
                    <label>URL аудио (полное)</label>
                    <input type="text" name="full_audio_url" value="<?php echo htmlspecialchars($med['full_audio_url'] ?? ''); ?>" placeholder="/assets/audio/meditations/...">
                </div>
                <div class="adm-field">
                    <label>URL аудио (demo)</label>
                    <input type="text" name="demo_audio_url" value="<?php echo htmlspecialchars($med['demo_audio_url'] ?? ''); ?>">
                </div>
            </div>

            <div class="adm-form-row">
                <div class="adm-field">
                    <label>Статус генерации</label>
                    <select name="generation_status">
                        <?php foreach (['ready','pending','generating','failed'] as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo $med['generation_status'] === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="adm-field">
                    <label>Тип</label>
                    <select name="type">
                        <option value="personal" <?php echo $med['type'] === 'personal' ? 'selected' : ''; ?>>personal</option>
                        <option value="general"  <?php echo $med['type'] === 'general'  ? 'selected' : ''; ?>>general</option>
                    </select>
                </div>
                <?php if ($med['type'] === 'general'): ?>
                <div class="adm-field">
                    <label>Категория</label>
                    <select name="category_id">
                        <option value="">— без категории —</option>
                        <?php foreach ($cats as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $med['category_id'] == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="adm-field" style="flex:0 0 120px">
                    <label>Цена (₽)</label>
                    <input type="number" name="price" value="<?php echo $med['price'] ?? 0; ?>" min="0">
                </div>
                <?php endif; ?>
            </div>

            <?php if ($med['full_audio_url']): ?>
            <div style="margin-bottom:16px;padding:12px;background:var(--bg);border-radius:6px">
                <div style="font-size:12px;color:var(--muted);margin-bottom:8px">Текущее аудио</div>
                <audio controls src="<?php echo htmlspecialchars($med['full_audio_url']); ?>" style="width:100%"></audio>
            </div>
            <?php endif; ?>

            <div style="display:flex;gap:10px;align-items:center">
                <button type="submit" class="adm-btn adm-btn--primary">Сохранить</button>
                <?php if ($med['generation_status'] !== 'ready'): ?>
                <form method="post" action="/admin/meditations/api/regenerate.php" style="display:inline">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <button type="submit" class="adm-btn adm-btn--ghost">↺ Перегенерировать</button>
                </form>
                <?php endif; ?>
                <form method="post" action="/admin/meditations/api/delete-one.php" onsubmit="return confirm('Удалить медитацию?')" style="display:inline;margin-left:auto">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <button type="submit" class="adm-btn adm-btn--danger">Удалить</button>
                </form>
            </div>
        </form>
    </div>
</div>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
