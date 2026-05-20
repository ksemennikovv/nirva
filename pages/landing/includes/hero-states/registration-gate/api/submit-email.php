<?php
/**
 * registration-gate/api/submit-email.php — Принимает email, генерирует и сохраняет код верификации.
 *
 * Вызывается из RegistrationGate.handleSubmitRegistrationEmail() (POST, JSON).
 *
 * Flow:
 *   1. Валидация email
 *   2. Генерация 6-значного кода
 *   3. Сохранение в verification_codes (срок 15 мин)
 *   4. Отправка письма
 *   5. Сохранение email в сессии
 *
 * Ответ: JSON { success, data, message, error }
 */

session_start();
header('Content-Type: application/json');

// Путь к корню: api/ → registration-gate/ → hero-states/ → includes/ → landing/ → pages/ → public_html/
$root = dirname(__DIR__, 6);

require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/AuthCodeRepository.php';

// ─── Входящие данные ──────────────────────────────────────────────────────────

$body  = json_decode(file_get_contents('php://input'), true);
$email = filter_var(trim($body['email'] ?? ''), FILTER_VALIDATE_EMAIL);

if (!$email) {
    echo json_encode([
        'success' => false,
        'data'    => null,
        'message' => 'Некорректный email',
        'error'   => 'invalid_email',
    ]);
    exit;
}

// ─── Генерация кода ───────────────────────────────────────────────────────────

$code      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 минут

// ─── Сохранить код в БД ───────────────────────────────────────────────────────

$authCodeRepo = new AuthCodeRepository();
$authCodeRepo->create($email, $code, $expiresAt);

// ─── Отправить письмо ─────────────────────────────────────────────────────────

$subject = 'Ваш код подтверждения Nirva';
$body    = "Ваш код подтверждения: {$code}\n\nКод действителен 15 минут.\n\nЕсли вы не запрашивали код — просто проигнорируйте это письмо.";
$headers = 'From: noreply@nirva.ru' . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';

mail($email, $subject, $body, $headers);

// ─── Сохранить email в сессии ─────────────────────────────────────────────────

$_SESSION['pending_email'] = $email;

// ─── Ответ ────────────────────────────────────────────────────────────────────

echo json_encode([
    'success' => true,
    'data'    => null,
    'message' => '',
    'error'   => null,
]);
