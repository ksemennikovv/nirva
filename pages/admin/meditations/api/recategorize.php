<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$db = Database::getConnection();

// Только POST — выполнить
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db->beginTransaction();
    try {
        // 1. Удалить старые general-медитации (ID < 62, без аудио, user_id IS NULL)
        $deleted = $db->exec(
            "DELETE FROM meditations WHERE type='general' AND user_id IS NULL AND id < 62"
        );

        // 2. Удалить старые общие категории
        $db->exec("DELETE FROM meditation_categories WHERE user_id IS NULL");

        // 3. Создать новые категории
        $categories = [
            ['Женственность и сексуальность', 1],
            ['Исцеление травм',               2],
            ['Уверенность в себе',            3],
            ['Самоценность',                  4],
            ['Реализация',                    5],
        ];
        $catIds = [];
        $insStmt = $db->prepare(
            "INSERT INTO meditation_categories (user_id, name, slug, sort_order) VALUES (NULL, ?, ?, ?)"
        );
        foreach ($categories as [$name, $order]) {
            $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII', $name)));
            $slug = trim($slug, '-');
            $insStmt->execute([$name, $slug, $order]);
            $catIds[$name] = (int)$db->lastInsertId();
        }

        // 4. Расставить медитации по категориям
        $assign = [
            // cat_name => [med_ids]
            'Женственность и сексуальность' => [68, 63],
            'Исцеление травм'               => [67],
            'Уверенность в себе'            => [66],
            'Самоценность'                  => [65, 62],
            'Реализация'                    => [64],
        ];
        $upStmt = $db->prepare("UPDATE meditations SET category_id=? WHERE id=?");
        $assigned = 0;
        foreach ($assign as $catName => $ids) {
            $catId = $catIds[$catName] ?? null;
            if (!$catId) continue;
            foreach ($ids as $medId) {
                $upStmt->execute([$catId, $medId]);
                $assigned++;
            }
        }

        $db->commit();
        $success = true;
        $msg = "Готово: удалено старых медитаций — $deleted. Создано категорий — " . count($categories) . ". Расставлено медитаций — $assigned.";
    } catch (\Exception $e) {
        $db->rollBack();
        $success = false;
        $msg = 'Ошибка: ' . $e->getMessage();
    }
}

// Предпросмотр что будет удалено
$toDelete = $db->query(
    "SELECT id, title, generation_status, full_audio_url FROM meditations WHERE type='general' AND user_id IS NULL AND id < 62 ORDER BY id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$toKeep = $db->query(
    "SELECT id, title FROM meditations WHERE type='general' AND user_id IS NULL AND id >= 62 ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$oldCats = $db->query(
    "SELECT name FROM meditation_categories WHERE user_id IS NULL ORDER BY sort_order"
)->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Реорганизация медитаций</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; color: #1e293b; }
h1 { font-size: 22px; margin-bottom: 4px; }
.sub { color: #64748b; margin-bottom: 24px; font-size: 14px; }
.card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.card h2 { font-size: 15px; margin: 0 0 12px; }
.danger { background: #fff5f5; border-color: #fecaca; }
.success-box { background: #f0fdf4; border-color: #bbf7d0; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
td, th { padding: 6px 10px; border-bottom: 1px solid #f1f5f9; text-align: left; }
th { color: #94a3b8; font-weight: 600; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.badge-red { background: #fee2e2; color: #dc2626; }
.badge-green { background: #dcfce7; color: #16a34a; }
.new-cats { display: flex; flex-wrap: wrap; gap: 8px; }
.new-cat { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 6px 12px; font-size: 13px; }
.new-cat ul { margin: 4px 0 0 16px; padding: 0; font-size: 12px; color: #475569; }
.btn { display: inline-block; padding: 10px 24px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; }
.btn-danger { background: #dc2626; color: #fff; }
.btn-back { background: #f1f5f9; color: #1e293b; text-decoration: none; }
.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
.alert-ok  { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
.alert-err { background: #fff5f5; border: 1px solid #fecaca; color: #dc2626; }
</style>
</head>
<body>

<a href="/admin/meditations/general.php" style="color:#64748b;font-size:13px;text-decoration:none">← Назад к медитациям</a>
<h1 style="margin-top:12px">Реорганизация общих медитаций</h1>
<p class="sub">Удаление старых записей и расстановка новых по категориям</p>

<?php if (isset($success)): ?>
<div class="alert <?php echo $success ? 'alert-ok' : 'alert-err'; ?>">
    <?php echo htmlspecialchars($msg); ?>
    <?php if ($success): ?><br><a href="/admin/meditations/general.php">Открыть медитации →</a><?php endif; ?>
</div>
<?php endif; ?>

<!-- Что будет удалено -->
<div class="card danger">
    <h2>🗑 Будет удалено (<?php echo count($toDelete); ?> старых general-медитаций, ID &lt; 62)</h2>
    <?php if (empty($toDelete)): ?>
    <p style="color:#16a34a;margin:0">Нечего удалять — старых медитаций нет.</p>
    <?php else: ?>
    <table>
        <tr><th>ID</th><th>Название</th><th>Статус</th><th>Аудио</th></tr>
        <?php foreach ($toDelete as $m): ?>
        <tr>
            <td><?php echo $m['id']; ?></td>
            <td><?php echo htmlspecialchars($m['title'] ?? '—'); ?></td>
            <td><span class="badge badge-<?php echo $m['generation_status'] === 'ready' ? 'green' : 'red'; ?>"><?php echo $m['generation_status']; ?></span></td>
            <td><?php echo $m['full_audio_url'] ? '🎧 есть' : '—'; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<!-- Что останется -->
<div class="card success-box">
    <h2>✅ Останется (ID ≥ 62)</h2>
    <table>
        <tr><th>ID</th><th>Название</th></tr>
        <?php foreach ($toKeep as $m): ?>
        <tr><td><?php echo $m['id']; ?></td><td><?php echo htmlspecialchars($m['title']); ?></td></tr>
        <?php endforeach; ?>
    </table>
</div>

<!-- Старые категории -->
<?php if (!empty($oldCats)): ?>
<div class="card">
    <h2>📁 Текущие категории (будут удалены)</h2>
    <div style="color:#64748b;font-size:13px"><?php echo htmlspecialchars(implode(', ', $oldCats)); ?></div>
</div>
<?php endif; ?>

<!-- Новые категории -->
<div class="card">
    <h2>🆕 Новые категории</h2>
    <div class="new-cats">
        <div class="new-cat"><strong>Женственность и сексуальность</strong><ul><li>Энергия божественной женщины (68)</li><li>Принятие своего тела (63)</li></ul></div>
        <div class="new-cat"><strong>Исцеление травм</strong><ul><li>Божественное исцеление (67)</li></ul></div>
        <div class="new-cat"><strong>Уверенность в себе</strong><ul><li>Возвращение силы (66)</li></ul></div>
        <div class="new-cat"><strong>Самоценность</strong><ul><li>Перерождение. Новая Я. (65)</li><li>Воссоединение с собой (62)</li></ul></div>
        <div class="new-cat"><strong>Реализация</strong><ul><li>Реализация. Состояние Звезды. (64)</li></ul></div>
    </div>
</div>

<!-- Кнопка -->
<?php if (!isset($success) || !$success): ?>
<form method="post" onsubmit="return confirm('Выполнить? Удалённые медитации не восстановить.')">
    <button type="submit" class="btn btn-danger">Выполнить реорганизацию</button>
    <a href="/admin/meditations/general.php" class="btn btn-back" style="margin-left:10px">Отмена</a>
</form>
<?php endif; ?>

</body>
</html>
