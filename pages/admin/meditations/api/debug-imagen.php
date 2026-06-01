<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/services.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$apiKey  = defined('NANOBANANA_API_KEY') ? NANOBANANA_API_KEY : '';
$baseUrl = 'https://api.nanobananaapi.ai/api/v1/nanobanana';

$step    = $_GET['step'] ?? 'submit';
$taskId  = $_GET['task_id'] ?? '';
$prompt  = 'divine healing meditation, soft golden light, spiritual atmosphere, no text';
?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="utf-8"><title>Debug NanoBanana API</title>
<style>
body { font-family: monospace; max-width: 900px; margin: 40px auto; padding: 0 20px; }
pre  { background: #f1f5f9; padding: 12px; border-radius: 6px; overflow: auto; font-size: 12px; white-space: pre-wrap; }
.ok  { color: #16a34a; } .err { color: #dc2626; }
.btn { padding: 10px 20px; background: #6366f1; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; margin-top: 10px; }
</style>
</head>
<body>

<a href="/admin/meditations/general.php" style="color:#64748b;font-size:13px">← Назад</a>
<h2>Debug: NanoBanana API</h2>

<p><strong>API Key:</strong> <?php echo substr($apiKey, 0, 8) . '...' . substr($apiKey, -4); ?></p>
<p><strong>Base URL:</strong> <?php echo $baseUrl; ?></p>

<?php if ($step === 'submit' || $step === 'credits'): ?>

<!-- Шаг 0: проверить баланс -->
<h3>Шаг 0: Баланс аккаунта</h3>
<?php
$ch = curl_init($baseUrl . '/get-credit');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);
?>
<p>HTTP: <strong><?php echo $code; ?></strong> <?php if ($err) echo '<span class="err">cURL: ' . $err . '</span>'; ?></p>
<pre><?php echo htmlspecialchars($resp ?: '(пустой ответ)'); ?></pre>

<!-- Шаг 1: отправить задачу -->
<h3>Шаг 1: Отправка задачи генерации</h3>
<p>Промт: <em><?php echo htmlspecialchars($prompt); ?></em></p>
<?php
$payload = json_encode(['prompt' => $prompt, 'type' => 'TEXTTOIAMGE', 'numImages' => 1]);

$ch = curl_init($baseUrl . '/generate');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
]);
$resp2 = curl_exec($ch);
$code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err2  = curl_error($ch);
curl_close($ch);

$data2  = json_decode($resp2, true);
$taskId = $data2['data']['taskId'] ?? null;
?>
<p>HTTP: <strong class="<?php echo $code2 === 200 ? 'ok' : 'err'; ?>"><?php echo $code2; ?></strong>
<?php if ($err2) echo '<span class="err">cURL: ' . $err2 . '</span>'; ?></p>
<pre><?php echo htmlspecialchars($resp2 ?: '(пустой ответ)'); ?></pre>

<?php if ($taskId): ?>
<p class="ok">✅ taskId получен: <strong><?php echo $taskId; ?></strong></p>
<a href="?step=poll&task_id=<?php echo urlencode($taskId); ?>" class="btn">Шаг 2: Проверить статус →</a>
<?php else: ?>
<p class="err">❌ taskId не получен. Смотри ответ выше.</p>
<?php endif; ?>

<?php elseif ($step === 'poll' && $taskId): ?>

<h3>Шаг 2: Опрос статуса (taskId: <?php echo htmlspecialchars($taskId); ?>)</h3>
<?php
$ch = curl_init($baseUrl . '/record-info?taskId=' . urlencode($taskId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
]);
$resp3 = curl_exec($ch);
$code3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data3  = json_decode($resp3, true);
$inner3 = $data3['data'] ?? [];
$flag   = $inner3['successFlag'] ?? '?';
?>
<p>HTTP: <strong><?php echo $code3; ?></strong> | successFlag: <strong><?php echo $flag; ?></strong></p>
<pre><?php echo htmlspecialchars($resp3 ?: '(пустой ответ)'); ?></pre>

<?php if ($flag === 1): ?>
<?php $imgUrl = $inner3['response']['resultImageUrl'] ?? null; ?>
<p class="ok">✅ Готово!</p>
<?php if ($imgUrl): ?>
<p>URL: <a href="<?php echo $imgUrl; ?>" target="_blank"><?php echo $imgUrl; ?></a></p>
<img src="<?php echo $imgUrl; ?>" style="max-width:100%;border-radius:8px;margin-top:10px">
<?php endif; ?>
<?php elseif ($flag === 0): ?>
<p style="color:#d97706">⏳ Ещё обрабатывается...</p>
<a href="?step=poll&task_id=<?php echo urlencode($taskId); ?>" class="btn">Обновить</a>
<?php else: ?>
<p class="err">❌ Ошибка (flag=<?php echo $flag; ?>): <?php echo htmlspecialchars($inner3['errorMessage'] ?? ''); ?></p>
<?php endif; ?>

<br><br><a href="?" class="btn" style="background:#94a3b8">← Начать заново</a>

<?php endif; ?>

</body>
</html>
