<?php
/**
 * pages/analysis/api/get-meditations.php
 *
 * GET ?id={analysisId} — возвращает медитации разбора с их статусами.
 * Используется для polling с фронтенда.
 */

session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/MeditationRepository.php';

$analysisId = (int)($_GET['id'] ?? 0);
$userId     = (int)($_SESSION['user_id'] ?? 0);

if (!$analysisId) {
    echo json_encode(['success' => false, 'error' => 'missing_id']);
    exit;
}

$medRepo     = new MeditationRepository();
$meditations = $medRepo->getByAnalysis($analysisId, $userId);

$allReady  = true;
$hasAny    = false;
$readyList = [];

foreach ($meditations as $med) {
    if ($med['generation_status'] !== 'ready') {
        $allReady = false;
    } else {
        $hasAny = true;
        $readyList[] = [
            'id'            => (int)$med['id'],
            'title'         => $med['title'],
            'description'   => $med['description'],
            'topic'         => $med['topic'],
            'full_audio_url' => $med['full_audio_url'],
            'demo_audio_url' => $med['demo_audio_url'],
            'image_url'     => $med['image_url'] ?? null,
            'price'         => $med['price'] ?? 0,
            'is_purchased'  => !empty($med['is_purchased']),
            'generation_status' => $med['generation_status'],
        ];
    }
}

$allMeditations = array_map(fn($m) => [
    'id'                => (int)$m['id'],
    'generation_status' => $m['generation_status'],
    'title'             => $m['title'] ?? null,
], $meditations);

echo json_encode([
    'success'    => true,
    'data'       => [
        'meditations'     => $allMeditations,
        'ready'           => $readyList,
        'all_ready'       => $allReady && !empty($meditations),
        'has_any_ready'   => $hasAny,
        'total'           => count($meditations),
    ],
]);
