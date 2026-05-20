<?php
/**
 * analysis/api/get-analysis.php — данные разбора для страницы.
 */
session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/middleware/auth.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/AnalysisRepository.php';
require_once $root . '/src/repositories/MeditationRepository.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['success' => false, 'error' => 'no_id']); exit; }

$analysisRepo = new AnalysisRepository();
$session      = $analysisRepo->getSession($id);

if (!$session || (int)$session['user_id'] !== $currentUserId) {
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

$medRepo    = new MeditationRepository();
$meditations = $medRepo->getByAnalysis($id);

$isFirstAnalysis = $analysisRepo->countCompleted($currentUserId) <= 1;

echo json_encode([
    'success' => true,
    'data' => [
        'analysis'        => $session,
        'meditations'     => $meditations,
        'is_first'        => $isFirstAnalysis,
    ],
]);
