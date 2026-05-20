<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$db    = Database::getConnection();
$flash = $_GET['saved'] ?? '';

// Категории с количеством медитаций
$stmt = $db->query("
    SELECT c.*, COUNT(m.id) AS meditation_count
    FROM meditation_categories c
    LEFT JOIN meditations m ON m.category_id = c.id AND m.user_id IS NULL
    WHERE c.user_id IS NULL
    GROUP BY c.id
    ORDER BY c.sort_order, c.id
");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Категории медитаций';
$activeNav = 'meditations';
require dirname(__DIR__) . '/_layout.php';
?>

<?php if ($flash === '1'): ?><div class="adm-alert adm-alert--success">Сохранено.</div><?php endif; ?>
<?php if ($flash === 'deleted'): ?><div class="adm-alert adm-alert--success">Категория удалена.</div><?php endif; ?>

<div style="display:flex;gap:12px;margin-bottom:20px">
    <a href="/admin/meditations/" class="adm-btn adm-btn--ghost adm-btn--sm">← Все медитации</a>
    <a href="/admin/meditations/general.php" class="adm-btn adm-btn--ghost adm-btn--sm">🎧 Общие медитации</a>
</div>

<!-- Список категорий -->
<div class="adm-card" style="margin-bottom:24px">
    <div class="adm-card__head"><div class="adm-card__title">Категории (<?php echo count($categories); ?>)</div></div>
    <table class="adm-table">
        <thead>
            <tr><th>ID</th><th>Название</th><th>Slug</th><th>Описание</th><th>Порядок</th><th>Медитаций</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $cat): ?>
        <tr>
            <td style="color:var(--muted)"><?php echo $cat['id']; ?></td>
            <td style="font-weight:500"><?php echo htmlspecialchars($cat['name']); ?></td>
            <td style="color:var(--muted);font-size:12px"><?php echo htmlspecialchars($cat['slug'] ?? ''); ?></td>
            <td style="color:var(--muted);font-size:12px;max-width:250px"><?php echo htmlspecialchars(mb_substr($cat['description'] ?? '', 0, 80)); ?></td>
            <td style="color:var(--muted);text-align:center"><?php echo $cat['sort_order']; ?></td>
            <td style="text-align:center"><a href="/admin/meditations/general.php?category_id=<?php echo $cat['id']; ?>" style="color:var(--accent)"><?php echo $cat['meditation_count']; ?></a></td>
            <td style="display:flex;gap:6px">
                <button onclick="openEditCat(<?php echo htmlspecialchars(json_encode($cat)); ?>)" class="adm-btn adm-btn--ghost adm-btn--sm">Изменить</button>
                <form method="post" action="/admin/meditations/api/delete-category.php" onsubmit="return confirm('Удалить категорию? Медитации останутся без категории.')">
                    <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                    <button type="submit" class="adm-btn adm-btn--danger adm-btn--sm">✕</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($categories)): ?>
        <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)">Категорий нет</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Форма добавления -->
<div class="adm-card">
    <div class="adm-card__head"><div class="adm-card__title" id="form-title">Добавить категорию</div></div>
    <div style="padding:20px">
        <form class="adm-form" method="post" action="/admin/meditations/api/save-category.php" id="cat-form">
            <input type="hidden" name="id" id="cat-id" value="">
            <div class="adm-form-row">
                <div class="adm-field">
                    <label>Название <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="name" id="cat-name" required placeholder="Спокойствие">
                </div>
                <div class="adm-field">
                    <label>Slug</label>
                    <input type="text" name="slug" id="cat-slug" placeholder="calm">
                </div>
                <div class="adm-field" style="flex:0 0 100px">
                    <label>Порядок</label>
                    <input type="number" name="sort_order" id="cat-sort" value="0" min="0">
                </div>
            </div>
            <div class="adm-field">
                <label>Описание</label>
                <textarea name="description" id="cat-desc" rows="2" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;font-size:13px;resize:vertical"></textarea>
            </div>
            <div style="display:flex;gap:10px">
                <button type="submit" class="adm-btn adm-btn--primary" id="cat-submit">Добавить</button>
                <button type="button" onclick="resetForm()" class="adm-btn adm-btn--ghost" id="cat-cancel" style="display:none">Отмена</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditCat(cat) {
    document.getElementById('cat-id').value    = cat.id;
    document.getElementById('cat-name').value  = cat.name;
    document.getElementById('cat-slug').value  = cat.slug || '';
    document.getElementById('cat-sort').value  = cat.sort_order;
    document.getElementById('cat-desc').value  = cat.description || '';
    document.getElementById('form-title').textContent = 'Редактировать категорию #' + cat.id;
    document.getElementById('cat-submit').textContent = 'Сохранить';
    document.getElementById('cat-cancel').style.display = '';
    document.getElementById('cat-form').scrollIntoView({behavior:'smooth'});
}
function resetForm() {
    document.getElementById('cat-id').value    = '';
    document.getElementById('cat-name').value  = '';
    document.getElementById('cat-slug').value  = '';
    document.getElementById('cat-sort').value  = '0';
    document.getElementById('cat-desc').value  = '';
    document.getElementById('form-title').textContent = 'Добавить категорию';
    document.getElementById('cat-submit').textContent = 'Добавить';
    document.getElementById('cat-cancel').style.display = 'none';
}
</script>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
