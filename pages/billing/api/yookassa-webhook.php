<?php
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/PaymentRepository.php';
require_once $root . '/src/repositories/SubscriptionRepository.php';
require_once $root . '/src/services/Payment/PaymentService.php';

$payload = json_decode(file_get_contents('php://input'), true);
if (!$payload) { http_response_code(400); exit; }

$service = new PaymentService();
$service->handleYooKassaWebhook($payload);

http_response_code(200);
echo 'ok';
