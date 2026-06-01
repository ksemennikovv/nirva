<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$db        = Database::getConnection();
$flash     = $_GET['saved'] ?? '';
$catFilter = (int)($_GET['category_id'] ?? 0);

$cats   = $db->query("SELECT id, name, sort_order FROM meditation_categories WHERE user_id IS NULL ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
$catMap = array_column($cats, 'name', 'id');

$where  = ["m.type = 'general'", "m.user_id IS NULL"];
$params = [];
if ($catFilter) { $where[] = 'm.category_id = ?'; $params[] = $catFilter; }
$whereSQL = 'WHERE ' . implode(' AND ', $where);

$stmt = $db->prepare("SELECT m.*, c.name AS category_name
    FROM meditations m LEFT JOIN meditation_categories c ON c.id = m.category_id
    $whereSQL ORDER BY c.sort_order, m.id");
$stmt->execute($params);
$meds = $stmt->fetchAll(PDO::FETCH_ASSOC);

$editId  = (int)($_GET['edit'] ?? 0);
$addNew  = isset($_GET['add']);
$editMed = null;
if ($editId) {
    $s = $db->prepare("SELECT * FROM meditations WHERE id = ? AND type = 'general'");
    $s->execute([$editId]);
    $editMed = $s->fetch(PDO::FETCH_ASSOC);
}

$pageTitle = 'Общие медитации';
$activeNav = 'meditations';
require dirname(__DIR__) . '/_layout.php';
?>

<?php if ($flash === '1'): ?><div class="adm-alert adm-alert--success">Сохранено.</div><?php endif; ?>
<?php if ($flash === 'deleted'): ?><div class="adm-alert adm-alert--success">Медитация удалена.</div><?php endif; ?>

<!-- Навигация и кнопка добавления -->
<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;align-items:center">
    <a href="/admin/meditations/" class="adm-btn adm-btn--ghost adm-btn--sm">← Все медитации</a>
    <a href="/admin/meditations/categories.php" class="adm-btn adm-btn--ghost adm-btn--sm">📁 Категории</a>
    <div style="height:20px;width:1px;background:var(--border)"></div>
    <?php foreach ($cats as $c): ?>
    <a href="?category_id=<?php echo $c['id']; ?>" class="adm-btn adm-btn--sm <?php echo $catFilter === (int)$c['id'] ? 'adm-btn--primary' : 'adm-btn--ghost'; ?>"><?php echo htmlspecialchars($c['name']); ?></a>
    <?php endforeach; ?>
    <?php if ($catFilter): ?><a href="?" class="adm-btn adm-btn--ghost adm-btn--sm">Все</a><?php endif; ?>
    <div style="flex:1"></div>
    <a href="?add=1<?php echo $catFilter ? '&category_id='.$catFilter : ''; ?>" class="adm-btn adm-btn--primary">+ Добавить медитацию</a>
</div>

<!-- Форма добавления (показывается при ?add=1 или ?edit=X) -->
<?php if ($addNew || $editMed): ?>
<div class="adm-card" style="margin-bottom:24px;border:2px solid var(--accent)">
    <div class="adm-card__head">
        <div class="adm-card__title"><?php echo $editMed ? '✏️ Редактировать медитацию #' . $editId : '➕ Новая общая медитация'; ?></div>
        <a href="/admin/meditations/general.php<?php echo $catFilter ? '?category_id='.$catFilter : ''; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">✕ Закрыть</a>
    </div>
    <div style="padding:20px">
        <form class="adm-form" method="post" action="/admin/meditations/api/save-general.php"
              enctype="multipart/form-data">
            <?php if ($editMed): ?><input type="hidden" name="id" value="<?php echo $editId; ?>"><?php endif; ?>
            <input type="hidden" name="redirect" value="/admin/meditations/general.php<?php echo $catFilter ? '?category_id='.$catFilter : ''; ?>">

            <div class="adm-form-row">
                <div class="adm-field">
                    <label>Название <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($editMed['title'] ?? ''); ?>" required placeholder="Спокойный сон">
                </div>
                <div class="adm-field">
                    <label>Категория</label>
                    <select name="category_id">
                        <option value="">— без категории —</option>
                        <?php foreach ($cats as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($editMed['category_id'] ?? $catFilter) == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="adm-field" style="flex:0 0 130px">
                    <label>Цена (₽)</label>
                    <input type="number" name="price" value="<?php echo $editMed['price'] ?? 0; ?>" min="0" step="1">
                </div>
            </div>

            <div class="adm-field">
                <label>Описание</label>
                <textarea name="description" rows="3" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;font-size:13px;resize:vertical"><?php echo htmlspecialchars($editMed['description'] ?? ''); ?></textarea>
            </div>

            <!-- Аудио: полное -->
            <div class="adm-field" style="background:var(--bg);padding:14px;border-radius:8px;margin-bottom:4px">
                <label style="font-weight:700;margin-bottom:10px;display:block">🎧 Полное аудио (full)</label>
                <?php if (!empty($editMed['full_audio_url'])): ?>
                <div style="margin-bottom:10px;display:flex;gap:10px;align-items:center">
                    <span style="font-size:12px;color:var(--muted)">Текущий файл:</span>
                    <a href="<?php echo htmlspecialchars($editMed['full_audio_url']); ?>" target="_blank" style="color:var(--accent);font-size:12px">🎧 <?php echo htmlspecialchars(basename($editMed['full_audio_url'])); ?></a>
                    <audio controls style="height:32px;flex:1" src="<?php echo htmlspecialchars($editMed['full_audio_url']); ?>"></audio>
                </div>
                <?php endif; ?>
                <div style="display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end">
                    <div class="adm-field" style="margin:0">
                        <label style="font-size:12px;color:var(--muted)">Загрузить MP3 файл</label>
                        <input type="file" name="full_audio_file" accept="audio/mpeg,audio/mp3,.mp3"
                               style="border:1px solid var(--border);border-radius:6px;padding:6px;width:100%;font-size:13px">
                    </div>
                    <div style="font-size:12px;color:var(--muted);padding-bottom:4px">— или —</div>
                    <div class="adm-field" style="margin:0;grid-column:1">
                        <label style="font-size:12px;color:var(--muted)">Вставить URL вручную</label>
                        <input type="text" name="full_audio_url" value="<?php echo htmlspecialchars($editMed['full_audio_url'] ?? ''); ?>"
                               placeholder="/assets/audio/meditations/file.mp3">
                    </div>
                </div>
            </div>

            <!-- Аудио: демо -->
            <div class="adm-field" style="background:var(--bg);padding:14px;border-radius:8px;margin-bottom:4px">
                <label style="font-weight:700;margin-bottom:10px;display:block">🎵 Демо аудио (preview, первые ~60 сек)</label>
                <?php if (!empty($editMed['demo_audio_url'])): ?>
                <div style="margin-bottom:10px;display:flex;gap:10px;align-items:center">
                    <span style="font-size:12px;color:var(--muted)">Текущий файл:</span>
                    <a href="<?php echo htmlspecialchars($editMed['demo_audio_url']); ?>" target="_blank" style="color:var(--accent);font-size:12px">🎵 <?php echo htmlspecialchars(basename($editMed['demo_audio_url'])); ?></a>
                    <audio controls style="height:32px;flex:1" src="<?php echo htmlspecialchars($editMed['demo_audio_url']); ?>"></audio>
                </div>
                <?php endif; ?>
                <div style="display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end">
                    <div class="adm-field" style="margin:0">
                        <label style="font-size:12px;color:var(--muted)">Загрузить MP3 файл</label>
                        <input type="file" name="demo_audio_file" accept="audio/mpeg,audio/mp3,.mp3"
                               style="border:1px solid var(--border);border-radius:6px;padding:6px;width:100%;font-size:13px">
                    </div>
                    <div style="font-size:12px;color:var(--muted);padding-bottom:4px">— или —</div>
                    <div class="adm-field" style="margin:0;grid-column:1">
                        <label style="font-size:12px;color:var(--muted)">Вставить URL вручную</label>
                        <input type="text" name="demo_audio_url" value="<?php echo htmlspecialchars($editMed['demo_audio_url'] ?? ''); ?>"
                               placeholder="/assets/audio/meditations/demo.mp3">
                    </div>
                </div>
            </div>

            <div class="adm-form-row" style="margin-top:12px">
                <div class="adm-field">
                    <label>Статус</label>
                    <select name="generation_status">
                        <option value="ready"   <?php echo ($editMed['generation_status'] ?? 'ready') === 'ready'   ? 'selected' : ''; ?>>✅ ready (отображается)</option>
                        <option value="pending" <?php echo ($editMed['generation_status'] ?? '') === 'pending' ? 'selected' : ''; ?>>⏳ pending</option>
                        <option value="failed"  <?php echo ($editMed['generation_status'] ?? '') === 'failed'  ? 'selected' : ''; ?>>❌ failed</option>
                    </select>
                </div>
                <div class="adm-field">
                    <label>Бесплатно в первый месяц</label>
                    <select name="is_free_first_month">
                        <option value="0" <?php echo empty($editMed['is_free_first_month']) ? 'selected' : ''; ?>>Нет (платная)</option>
                        <option value="1" <?php echo !empty($editMed['is_free_first_month']) ? 'selected' : ''; ?>>Да (бесплатно)</option>
                    </select>
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:8px">
                <button type="submit" class="adm-btn adm-btn--primary"><?php echo $editMed ? 'Сохранить изменения' : 'Добавить медитацию'; ?></button>
                <a href="/admin/meditations/general.php<?php echo $catFilter ? '?category_id='.$catFilter : ''; ?>" class="adm-btn adm-btn--ghost">Отмена</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Список медитаций -->
<div class="adm-card">
    <div class="adm-card__head">
        <div class="adm-card__title">Медитации (<?php echo count($meds); ?>)</div>
    </div>
    <table class="adm-table">
        <thead>
            <tr><th>ID</th><th>Название</th><th>Категория</th><th>Статус</th><th>Цена</th><th>Аудио</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($meds as $m):
            $stCls = ['ready'=>'active','pending'=>'pending','failed'=>'cancelled','generating'=>'generating'][$m['generation_status']] ?? 'none';
        ?>
        <tr>
            <td style="color:var(--muted)"><?php echo $m['id']; ?></td>
            <td style="font-weight:500"><?php echo htmlspecialchars($m['title'] ?? '—'); ?></td>
            <td style="color:var(--muted);font-size:12px"><?php echo htmlspecialchars($m['category_name'] ?? '—'); ?></td>
            <td><span class="adm-badge adm-badge--<?php echo $stCls; ?>"><?php echo $m['generation_status']; ?></span></td>
            <td style="color:var(--muted)"><?php echo $m['price'] > 0 ? number_format($m['price'], 0, '.', ' ') . ' ₽' : 'Бесплатно'; ?></td>
            <td>
                <?php if ($m['full_audio_url']): ?>
                <div style="display:flex;gap:6px;align-items:center">
                    <a href="<?php echo htmlspecialchars($m['full_audio_url']); ?>" target="_blank" style="color:var(--accent)" title="Полное">🎧</a>
                    <?php if ($m['demo_audio_url']): ?><a href="<?php echo htmlspecialchars($m['demo_audio_url']); ?>" target="_blank" style="color:var(--muted)" title="Демо">🎵</a><?php endif; ?>
                </div>
                <?php else: ?>
                <span style="color:var(--muted)">—</span>
                <?php endif; ?>
            </td>
            <td style="display:flex;gap:6px;white-space:nowrap">
                <a href="?edit=<?php echo $m['id']; ?><?php echo $catFilter ? '&category_id='.$catFilter : ''; ?>"
                   class="adm-btn adm-btn--ghost adm-btn--sm">Изменить</a>
                <form method="post" action="/admin/meditations/api/delete-general.php"
                      onsubmit="return confirm('Удалить медитацию «<?php echo htmlspecialchars($m['title']); ?>»?')">
                    <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                    <button type="submit" class="adm-btn adm-btn--danger adm-btn--sm">✕</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($meds)): ?>
        <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)">
            Медитаций нет. <a href="?add=1" style="color:var(--accent)">Добавить первую →</a>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
