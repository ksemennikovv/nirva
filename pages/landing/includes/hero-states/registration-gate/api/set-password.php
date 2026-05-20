<?php
/**
 * registration-gate/api/set-password.php — Устанавливает пароль после верификации email.
 * Вызывается на шаге 3 registration-gate.
 */

session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 6);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/UserRepository.php';

$userId = $_SESSION['user_id'] ?? null;

if (!$userId || ($_SESSION['registration_step'] ?? '') !== 'password') {
    echo json_encode(['success' => false, 'error' => 'invalid_state', 'message' => 'Неверное состояние сессии']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true);
$password = $body['password']         ?? '';
$confirm  = $body['password_confirm'] ?? '';

if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'error' => 'too_short', 'message' => 'Пароль должен быть не менее 8 символов']);
    exit;
}

if ($password !== $confirm) {
    echo json_encode(['success' => false, 'error' => 'mismatch', 'message' => 'Пароли не совпадают']);
    exit;
}

$userRepo = new UserRepository();
$userRepo->setPassword((int)$userId, $password);

unset($_SESSION['registration_step']);

echo json_encode([
    'success' => true,
    'data'    => ['redirect' => '/dashboard/'],
    'message' => '',
    'error'   => null,
]);
