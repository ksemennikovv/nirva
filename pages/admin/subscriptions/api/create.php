<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/repositories/SubscriptionRepository.php';

$userId            = (int)($_POST['user_id'] ?? 0);
$plan              = in_array($_POST['plan'] ?? '', ['start','basic','transformation']) ? $_POST['plan'] : 'start';
$analysesPerMonth  = max(1, (int)($_POST['analyses_per_month'] ?? 1));
$startsAt          = $_POST['starts_at'] ?? date('Y-m-d');
$expiresAt         = $_POST['expires_at'] ?? date('Y-m-d', strtotime('+30 days'));
$redirect          = $_POST['redirect'] ?? '/admin/subscriptions/';

if (!$userId) { http_response_code(400); exit('Bad request'); }

$repo = new SubscriptionRepository();
$repo->create([
    'user_id'            => $userId,
    'plan'               => $plan,
    'period'             => 'manual',
    'analyses_per_month' => $analysesPerMonth,
    'starts_at'          => $startsAt,
    'expires_at'         => $expiresAt,
]);

header('Location: ' . $redirect);
