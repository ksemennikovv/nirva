<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$db = Database::getConnection();

// Проверяем существование таблицы
$tableExists = false;
try {
    $db->query("SELECT 1 FROM practices LIMIT 1");
    $tableExists = true;
} catch (\PDOException $e) {}

$practices = [];
$usageCounts = [];
if ($tableExists) {
    $practices = $db->query("SELECT * FROM practices ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    // Сколько разборов использует каждую практику
    $ucStmt = $db->query(
        "SELECT selected_practice, COUNT(*) as cnt FROM analysis_sessions
         WHERE selected_practice IS NOT NULL AND selected_practice != ''
         GROUP BY selected_practice"
    );
    foreach ($ucStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $usageCounts[$row['selected_practice']] = (int)$row['cnt'];
    }
}

$flash   = $_GET['saved'] ?? '';
$errMsg  = $_GET['error'] ?? '';

$pageTitle = 'Практики';
$activeNav = 'practices';
require dirname(__DIR__) . '/_layout.php';
?>

<?php if ($flash === '1'): ?><div class="adm-alert adm-alert--success">Сохранено.</div><?php endif; ?>
<?php if ($errMsg): ?><div class="adm-alert adm-alert--error"><?php echo htmlspecialchars(urldecode($errMsg)); ?></div><?php endif; ?>

<?php if (!$tableExists): ?>
<div class="adm-card" style="padding:20px">
    <p style="color:var(--danger)">⚠️ Таблица <code>practices</code> не найдена. Выполните миграцию <code>010_practices.sql</code>.</p>
</div>
<?php else: ?>

<!-- Список практик -->
<div class="adm-card" style="margin-bottom:24px">
    <div class="adm-card__head">
        <div class="adm-card__title">Практики (<?php echo count($practices); ?>)</div>
        <button class="adm-btn adm-btn--primary adm-btn--sm" onclick="openForm()">+ Добавить практику</button>
    </div>
    <table class="adm-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Slug</th>
                <th>Название</th>
                <th>Описание</th>
                <th>Порядок</th>
                <th>Используется</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($practices as $pr): ?>
        <tr>
            <td style="color:var(--muted)"><?php echo $pr['id']; ?></td>
            <td><code style="font-size:11px"><?php echo htmlspecialchars($pr['slug']); ?></code></td>
            <td style="font-weight:600"><?php echo htmlspecialchars($pr['title']); ?></td>
            <td style="color:var(--muted);font-size:12px;max-width:300px"><?php echo htmlspecialchars(mb_substr($pr['description'] ?? '', 0, 100)); ?></td>
            <td style="color:var(--muted)"><?php echo $pr['sort_order']; ?></td>
            <td>
                <?php $cnt = $usageCounts[$pr['slug']] ?? 0; ?>
                <?php if ($cnt > 0): ?>
                <a href="/admin/analyses/?practice=<?php echo urlencode($pr['slug']); ?>" style="color:var(--accent)"><?php echo $cnt; ?> разб.</a>
                <?php else: ?>
                <span style="color:var(--muted)">—</span>
                <?php endif; ?>
            </td>
            <td style="white-space:nowrap">
                <button class="adm-btn adm-btn--ghost adm-btn--sm"
                        onclick="editPractice(<?php echo htmlspecialchars(json_encode($pr, JSON_UNESCAPED_UNICODE)); ?>)">
                    Редактировать
                </button>
                <form method="post" action="/admin/practices/api/delete.php" style="display:inline"
                      onsubmit="return confirm('Удалить практику «<?php echo htmlspecialchars($pr['title']); ?>»?')">
                    <input type="hidden" name="id" value="<?php echo $pr['id']; ?>">
                    <button type="submit" class="adm-btn adm-btn--danger adm-btn--sm">Удалить</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($practices)): ?>
        <tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted)">Практик нет</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Форма добавления/редактирования -->
<div id="practice-form-wrap" style="display:none">
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head">
        <div class="adm-card__title" id="form-title">Новая практика</div>
        <button class="adm-btn adm-btn--ghost adm-btn--sm" onclick="closeForm()">✕ Закрыть</button>
    </div>
    <div style="padding:20px">
        <form class="adm-form" method="post" action="/admin/practices/api/save.php">
            <input type="hidden" name="id" id="f-id" value="">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div class="adm-field">
                    <label>Slug <span style="color:var(--muted);font-size:11px">(уникальный, латиница)</span></label>
                    <input type="text" name="slug" id="f-slug" placeholder="shapka-monomakha" required>
                </div>
                <div class="adm-field">
                    <label>Название</label>
                    <input type="text" name="title" id="f-title" placeholder="Шапка Мономаха" required>
                </div>
            </div>
            <div class="adm-field">
                <label>Описание</label>
                <textarea name="description" id="f-description" rows="3" style="width:100%;resize:vertical"></textarea>
            </div>
            <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
                <div class="adm-field">
                    <label>Ссылка на видео (необязательно)</label>
                    <input type="url" name="video_url" id="f-video_url" placeholder="https://...">
                </div>
                <div class="adm-field">
                    <label>Порядок сортировки</label>
                    <input type="number" name="sort_order" id="f-sort_order" value="0" min="0">
                </div>
            </div>
            <div style="display:flex;gap:10px">
                <button type="submit" class="adm-btn adm-btn--primary">Сохранить</button>
                <button type="button" class="adm-btn adm-btn--ghost" onclick="closeForm()">Отмена</button>
            </div>
        </form>
    </div>
</div>
</div>

<script>
function openForm() {
    document.getElementById('practice-form-wrap').style.display = 'block';
    document.getElementById('form-title').textContent = 'Новая практика';
    document.getElementById('f-id').value = '';
    ['slug','title','description','video_url'].forEach(k => document.getElementById('f-'+k).value = '');
    document.getElementById('f-sort_order').value = '0';
    document.getElementById('practice-form-wrap').scrollIntoView({behavior:'smooth'});
}
function closeForm() {
    document.getElementById('practice-form-wrap').style.display = 'none';
}
function editPractice(pr) {
    document.getElementById('practice-form-wrap').style.display = 'block';
    document.getElementById('form-title').textContent = 'Редактировать: ' + pr.title;
    document.getElementById('f-id').value         = pr.id;
    document.getElementById('f-slug').value       = pr.slug;
    document.getElementById('f-title').value      = pr.title;
    document.getElementById('f-description').value = pr.description || '';
    document.getElementById('f-video_url').value  = pr.video_url || '';
    document.getElementById('f-sort_order').value = pr.sort_order || 0;
    document.getElementById('practice-form-wrap').scrollIntoView({behavior:'smooth'});
}
</script>

<?php endif; ?>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
