<?php
/**
 * login/api/forgot-password.php — Генерация токена и отправка письма для сброса пароля.
 */
session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/assets/php/helpers.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/UserRepository.php';

$body  = json_decode(file_get_contents('php://input'), true);
$email = trim(strtolower($body['email'] ?? ''));

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Введите корректный email']);
    exit;
}

$userRepo = new UserRepository();
$user     = $userRepo->findByEmail($email);

// Всегда отвечаем успехом — не раскрываем, есть ли аккаунт
if (!$user) {
    echo json_encode(['success' => true]);
    exit;
}

$db    = Database::getConnection();
$token = bin2hex(random_bytes(32));
$exp   = date('Y-m-d H:i:s', time() + 3600);

// Удаляем старые токены этого пользователя
$db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$user['id']]);

$db->prepare('INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)')
   ->execute([$user['id'], $token, $exp]);

$resetUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/pages/login/reset-password.php?token=' . $token;

$subject = 'Восстановление пароля — Nirva';
$message = "Здравствуйте!\n\nДля сброса пароля перейдите по ссылке:\n{$resetUrl}\n\nСсылка действует 1 час.\n\nЕсли вы не запрашивали сброс пароля — просто проигнорируйте это письмо.";
$headers = 'From: noreply@' . $_SERVER['HTTP_HOST'] . "\r\nContent-Type: text/plain; charset=UTF-8";

mail($email, $subject, $message, $headers);

echo json_encode(['success' => true]);
