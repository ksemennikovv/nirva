<?php
/**
 * login/api/reset-password.php — Установка нового пароля по токену.
 */
session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/UserRepository.php';

$body     = json_decode(file_get_contents('php://input'), true);
$token    = trim($body['token'] ?? '');
$password = $body['password'] ?? '';

if (!$token || strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Некорректные данные']);
    exit;
}

$db  = Database::getConnection();
$stmt = $db->prepare('
    SELECT * FROM password_reset_tokens
    WHERE token = ? AND expires_at > NOW() AND used_at IS NULL
    LIMIT 1
');
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Ссылка недействительна или истекла']);
    exit;
}

$userRepo = new UserRepository();
$userRepo->setPassword((int)$row['user_id'], $password);

$db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')
   ->execute([$row['id']]);

// Автологин после смены пароля
$_SESSION['user_id'] = (int)$row['user_id'];

echo json_encode(['success' => true]);
