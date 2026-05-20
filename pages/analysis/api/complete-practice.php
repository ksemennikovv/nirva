<?php
session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/middleware/auth.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/AnalysisRepository.php';

$body = json_decode(file_get_contents('php://input'), true);
$id   = (int)($body['analysis_id'] ?? 0);

if (!$id) { echo json_encode(['success' => false, 'error' => 'no_id']); exit; }

$repo    = new AnalysisRepository();
$session = $repo->getSession($id);

if (!$session || (int)$session['user_id'] !== $currentUserId) {
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

$repo->markPracticeCompleted($id);

echo json_encode(['success' => true]);
