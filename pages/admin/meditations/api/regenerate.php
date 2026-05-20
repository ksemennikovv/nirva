<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/repositories/MeditationRepository.php';

$id = (int)($_POST['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Bad request'); }

$repo = new MeditationRepository();
$repo->updateStatus($id, 'pending', ['generation_job_id' => null]);

// Launch background worker
$script = $root . '/src/scripts/process-meditations.php';
if (PHP_OS_FAMILY === 'Windows') {
    popen('start /B php "' . $script . '"', 'r');
} else {
    exec('php ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
}

header('Location: /admin/meditations/?regenerated=1');
