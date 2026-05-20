<?php
session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/AnalysisRepository.php';

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
if (!$currentUserId) {
    echo json_encode(['success' => false, 'error' => 'unauthorized', 'message' => 'Необходима авторизация']);
    exit;
}

require $root . '/src/middleware/subscription.php';

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$initialText = trim($body['initial_text'] ?? '');
$isPaywall   = !empty($body['paywall']);

// Валидация: нужен начальный текст
if (!$initialText) {
    echo json_encode(['success' => false, 'error' => 'empty_text', 'message' => 'Напишите, что вас беспокоит']);
    exit;
}

// Валидация: лимит подписки (пропускаем в paywall-режиме — модаль откроется после первого ответа)
if (!$isPaywall && $subPaywallActive) {
    echo json_encode(['success' => false, 'error' => 'limit_reached', 'message' => 'Лимит разборов на этот месяц исчерпан']);
    exit;
}

$repo = new AnalysisRepository();
$id   = $repo->createSession($currentUserId);

$_SESSION['analysis_session_id'] = $id;
$_SESSION['analysis_topic']      = '';

echo json_encode(['success' => true, 'id' => $id]);
