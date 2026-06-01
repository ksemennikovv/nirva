<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/middleware/auth.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/MeditationRepository.php';

header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$meditationId = (int)($body['meditation_id'] ?? 0);
$durationSec  = max(0, (int)($body['duration_sec']  ?? 0));
$completed    = !empty($body['completed']);

if ($meditationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid meditation_id']);
    exit;
}

$repo = new MeditationRepository();
$repo->recordListen($currentUserId, $meditationId, $durationSec, $completed);

echo json_encode(['success' => true]);
