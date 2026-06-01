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
require_once $root . '/src/services/ImageGeneration/ImageHistoryService.php';

$id       = (int)($_POST['id']            ?? 0);
$provider = trim($_POST['provider']       ?? '');
$prompt   = trim($_POST['custom_prompt']  ?? '');

if (!$id || !$provider) { http_response_code(400); exit('Bad request'); }

$db   = Database::getConnection();
$stmt = $db->prepare(
    "SELECT m.*, c.name AS category_name FROM meditations m
     LEFT JOIN meditation_categories c ON c.id = m.category_id WHERE m.id = ?"
);
$stmt->execute([$id]);
$med = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$med) { http_response_code(404); exit('Not found'); }

// Генерируем — кастомный промт или через ImageGenerationService
try {
    require_once $root . '/src/services/ImageGeneration/FluxImageService.php';
    require_once $root . '/src/services/ImageGeneration/GeminiImageService.php';

    if ($provider === 'imagen') {
        $model       = BusinessConfig::setting('imagen_model', 'nano-banana-2');
        $aspectRatio = BusinessConfig::setting('imagen_aspect_ratio', '9:16');
        $resolution  = BusinessConfig::setting('imagen_resolution', '1K');
        $modelLabel  = $model . ($resolution !== '1K' ? ' ' . $resolution : '');
        $svc         = new GeminiImageService($model, $aspectRatio, $resolution);
        $imageUrl    = $prompt
            ? $svc->generate($prompt, $id)
            : ImageGenerationService::generate($provider, $med, $med['category_name'] ?? '');
    } elseif ($provider === 'flux') {
        $fluxModel  = BusinessConfig::setting('flux_model', 'fal-ai/flux-pro/v1.1');
        $modelLabel = basename($fluxModel); // flux-pro/v1.1 → v1.1, flux/schnell → schnell
        $svc        = new FluxImageService($fluxModel);
        $imageUrl   = $prompt
            ? $svc->generate($prompt, $id)
            : ImageGenerationService::generate($provider, $med, $med['category_name'] ?? '');
    } else {
        $imageUrl = null;
    }
} catch (\Throwable $e) {
    error_log("[generate-image.php] Error: " . $e->getMessage());
    $imageUrl = null;
}

// Переподключаемся после долгой генерации (NanoBanana polling)
Database::reconnect();
$db = Database::getConnection();

if ($imageUrl) {
    $history = new ImageHistoryService($db);
    $history->archiveCurrent($id, 'generated', $provider); // сохраняем СТАРУЮ в историю
    // Сохраняем новую с меткой модели
    $db->prepare("UPDATE meditations SET image_url = ?, generation_provider = ? WHERE id = ?")
       ->execute([$imageUrl, $modelLabel ?? $provider, $id]);
    header('Location: /admin/meditations/edit.php?id=' . $id . '&gen=1');
} else {
    header('Location: /admin/meditations/edit.php?id=' . $id . '&gen=fail');
}
exit;
