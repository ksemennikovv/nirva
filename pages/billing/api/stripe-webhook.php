<?php
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/PaymentRepository.php';
require_once $root . '/src/repositories/SubscriptionRepository.php';
require_once $root . '/src/services/Payment/PaymentService.php';

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!$payload || !$sigHeader) { http_response_code(400); exit; }

$service = new PaymentService();
$ok = $service->handleStripeWebhook($payload, $sigHeader);

http_response_code($ok ? 200 : 400);
echo $ok ? 'ok' : 'error';
