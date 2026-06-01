<?php
session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/src/middleware/auth.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/DiaryRepository.php';
require_once $root . '/src/repositories/SubscriptionRepository.php';

$body = json_decode(file_get_contents('php://input'), true);

$diaryRepo = new DiaryRepository();
$subRepo   = new SubscriptionRepository();

$subscription = $subRepo->getActive($currentUserId);
$entryCount   = $diaryRepo->countUserEntries($currentUserId);
$freeLimit    = BusinessConfig::diaryFreeEntriesLimit();

if (!$subscription && $entryCount >= $freeLimit) {
    echo json_encode(['success' => false, 'error' => 'limit_reached']);
    exit;
}

$entryId = $diaryRepo->createEntry($currentUserId);
$_SESSION['current_diary_entry_id'] = $entryId;

echo json_encode(['success' => true, 'entry_id' => $entryId]);
