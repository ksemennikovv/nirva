<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/config/services.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/services/ImageGeneration/ImageGenerationService.php';

$db = Database::getConnection();

// Медитации для теста
$tests = [
    ['id' => 68, 'provider' => 'flux',   'label' => 'Fal.ai — Flux Pro 1.1'],
    ['id' => 67, 'provider' => 'imagen', 'label' => 'NanoBanana — Imagen 3'],
];

$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($tests as $test) {
        $stmt = $db->prepare("SELECT * FROM meditations WHERE id = ?");
        $stmt->execute([$test['id']]);
        $med = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$med) {
            $results[] = ['test' => $test, 'error' => 'Медитация не найдена (id=' . $test['id'] . ')'];
            continue;
        }

        // Получаем категорию
        $category = '';
        if (!empty($med['category_id'])) {
            $cat = $db->query("SELECT name FROM meditation_categories WHERE id = " . (int)$med['category_id'])->fetch(\PDO::FETCH_ASSOC);
            $category = $cat['name'] ?? '';
        }

        $start = microtime(true);
        $imageUrl = ImageGenerationService::generate($test['provider'], $med, $category);
        $elapsed = round(microtime(true) - $start, 1);

        // Переподключаемся к БД — соединение могло упасть за время генерации
        Database::reconnect();
        $db = Database::getConnection();

        if ($imageUrl) {
            $db->prepare("UPDATE meditations SET image_url = ? WHERE id = ?")->execute([$imageUrl, $test['id']]);
            $results[] = ['test' => $test, 'med' => $med, 'url' => $imageUrl, 'elapsed' => $elapsed];
        } else {
            $results[] = ['test' => $test, 'med' => $med, 'error' => 'Генерация не удалась — смотри php error log', 'elapsed' => $elapsed];
        }
    }
}

// Читаем промты до генерации (пока соединение живо)
$all = $db->query('SELECT key_name, value FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Тест генерации изображений</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 960px; margin: 40px auto; padding: 0 20px; color: #1e293b; }
h1 { font-size: 22px; }
.card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 16px; }
.ok   { border-color: #86efac; background: #f0fdf4; }
.err  { border-color: #fca5a5; background: #fff5f5; }
pre   { background: #f8fafc; padding: 10px; border-radius:4px; font-size:11px; overflow:auto; white-space:pre-wrap; }
img   { max-width: 100%; border-radius: 8px; margin-top: 10px; box-shadow: 0 4px 12px rgba(0,0,0,.15); }
.badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; margin-right:6px; }
.flux   { background:#dbeafe; color:#1d4ed8; }
.imagen { background:#fef9c3; color:#854d0e; }
.btn { display:inline-block; padding:12px 28px; border-radius:6px; font-size:15px; font-weight:600;
       background:#6366f1; color:#fff; border:none; cursor:pointer; }
.btn:disabled { opacity:.6; }
table { width:100%; border-collapse:collapse; font-size:13px; }
td,th { padding:6px 10px; border-bottom:1px solid #f1f5f9; text-align:left; }
th { color:#94a3b8; }
</style>
</head>
<body>

<a href="/admin/meditations/general.php" style="color:#64748b;font-size:13px;text-decoration:none">← Назад</a>
<h1 style="margin-top:12px">Тест генерации изображений</h1>

<!-- Что будет генерироваться -->
<div class="card" style="margin-bottom:24px">
    <h2 style="font-size:15px;margin:0 0 12px">Тестовые задания</h2>
    <table>
        <tr><th>Медитация</th><th>ID</th><th>Провайдер</th><th>Промт (preview)</th></tr>
        <?php foreach ($tests as $t):
            $stmt = $db->prepare("SELECT title, description, type FROM meditations WHERE id = ?");
            $stmt->execute([$t['id']]);
            $m = $stmt->fetch(\PDO::FETCH_ASSOC);
            $promptKey = ($m['type'] ?? 'general') === 'personal' ? 'image_prompt_personal' : 'image_prompt_general';
            $styleKey  = 'image_style_' . $t['provider'];
            $template  = $all[$promptKey] ?? '(не задан)';
            $style     = $all[$styleKey]  ?? '';
            $previewPrompt = mb_substr(strtr($template, [
                '{title}'       => $m['title'] ?? '',
                '{description}' => mb_substr($m['description'] ?? '', 0, 40),
                '{topic}'       => '',
                '{category}'    => '',
                '{style}'       => $style,
            ]), 0, 150) . '...';
        ?>
        <tr>
            <td><strong><?php echo htmlspecialchars($m['title'] ?? '—'); ?></strong></td>
            <td style="color:#64748b"><?php echo $t['id']; ?></td>
            <td><span class="badge <?php echo $t['provider']; ?>"><?php echo $t['label']; ?></span></td>
            <td style="color:#64748b;font-size:11px"><?php echo htmlspecialchars($previewPrompt); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<!-- Результаты -->
<?php if (!empty($results)): ?>
<h2 style="font-size:16px;margin-bottom:12px">Результаты</h2>
<?php foreach ($results as $r): ?>
<div class="card <?php echo isset($r['error']) ? 'err' : 'ok'; ?>">
    <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px">
        <span class="badge <?php echo $r['test']['provider']; ?>"><?php echo $r['test']['label']; ?></span>
        <strong><?php echo htmlspecialchars($r['med']['title'] ?? 'ID ' . $r['test']['id']); ?></strong>
        <?php if (isset($r['elapsed'])): ?><span style="color:#64748b;font-size:12px"><?php echo $r['elapsed']; ?> сек</span><?php endif; ?>
    </div>

    <?php if (isset($r['error'])): ?>
    <div style="color:#dc2626;font-size:13px">❌ <?php echo htmlspecialchars($r['error']); ?></div>
    <?php else: ?>
    <div style="color:#16a34a;font-size:13px;margin-bottom:8px">✅ Готово — <?php echo htmlspecialchars($r['url']); ?></div>
    <img src="<?php echo htmlspecialchars($r['url']); ?>" alt="<?php echo htmlspecialchars($r['med']['title']); ?>">
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Кнопка -->
<?php if (empty($results)): ?>
<p style="color:#64748b;font-size:13px">
    Нажми кнопку — скрипт сгенерирует картинки через оба сервиса и сохранит их в базу.<br>
    <strong>Flux</strong> ответит за ~15-20 сек, <strong>NanoBanana</strong> — до 90 сек. Страница может зависнуть на это время — это нормально.
</p>
<form method="post" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Генерируем... подождите до 2 минут'">
    <button type="submit" class="btn">🎨 Запустить тест генерации</button>
</form>
<?php else: ?>
<form method="post" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Генерируем...'">
    <button type="submit" class="btn" style="background:#94a3b8">↺ Сгенерировать заново</button>
</form>
<?php endif; ?>

</body>
</html>
