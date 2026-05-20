<?php
/**
 * login/api/login.php — Аутентификация по email + password.
 */

session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/UserRepository.php';

$body     = json_decode(file_get_contents('php://input'), true);
$email    = trim(strtolower($body['email'] ?? ''));
$password = $body['password'] ?? '';

if (!$email || !$password) {
    echo json_encode(['success' => false, 'message' => 'Введите email и пароль', 'error' => 'empty_fields']);
    exit;
}

$userRepo = new UserRepository();
$user     = $userRepo->findByEmail($email);

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Неверный email или пароль', 'error' => 'not_found']);
    exit;
}

if (!$userRepo->verifyPassword((int)$user['id'], $password)) {
    echo json_encode(['success' => false, 'message' => 'Неверный email или пароль', 'error' => 'wrong_password']);
    exit;
}

$_SESSION['user_id'] = (int)$user['id'];

echo json_encode([
    'success' => true,
    'data'    => ['redirect' => '/dashboard/'],
    'message' => '',
    'error'   => null,
]);
