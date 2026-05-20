<?php
session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/middleware/auth.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/PaymentRepository.php';
require_once $root . '/src/repositories/SubscriptionRepository.php';
require_once $root . '/src/services/Payment/PaymentService.php';

$body   = json_decode(file_get_contents('php://input'), true);
$plan   = $body['plan']   ?? '';
$period = $body['period'] ?? '';

$validPlans   = ['start', 'basic', 'transformation'];
$validPeriods = ['monthly', '6months', '12months'];

if (!in_array($plan, $validPlans) || !in_array($period, $validPeriods)) {
    echo json_encode(['success' => false, 'error' => 'invalid_params']);
    exit;
}

$service    = new PaymentService();
$ip         = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$provider   = PaymentService::detectProvider($ip);
$returnUrl  = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/billing/';

if ($provider === 'yookassa') {
    $result = $service->createYooKassaPayment($currentUserId, $plan, $period, $returnUrl);
} else {
    $result = $service->createStripePayment($currentUserId, $plan, $period, $returnUrl);
}

echo json_encode($result);
