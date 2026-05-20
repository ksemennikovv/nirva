<?php
/**
 * registration-gate/api/resend-code.php — Повторно отправляет код верификации.
 *
 * Вызывается из RegistrationGate.handleResendRegistrationCode() (POST).
 *
 * Rate limit: не более одного кода в 60 секунд.
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

// ─── Проверить наличие ожидающего email ───────────────────────────────────────

$email = $_SESSION['pending_email'] ?? null;

if (!$email) {
    echo json_encode([
        'success' => false,
        'data'    => null,
        'message' => 'Сессия истекла. Начните заново.',
        'error'   => 'no_pending_email',
    ]);
    exit;
}

// ─── Rate limit: не чаще одного раза в 60 секунд ─────────────────────────────

$authCodeRepo = new AuthCodeRepository();
$lastCode     = $authCodeRepo->findLastByEmail($email);

if ($lastCode && (time() - strtotime($lastCode['created_at'])) < 60) {
    echo json_encode([
        'success' => false,
        'data'    => null,
        'message' => 'Подождите 60 секунд перед повторным запросом',
        'error'   => 'rate_limit',
    ]);
    exit;
}

// ─── Генерация и сохранение нового кода ──────────────────────────────────────

$code      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 минут

$authCodeRepo->create($email, $code, $expiresAt);

// ─── Отправить письмо ─────────────────────────────────────────────────────────

$subject = 'Новый код подтверждения Nirva';
$body    = "Ваш новый код подтверждения: {$code}\n\nКод действителен 15 минут.";
$headers = 'From: noreply@nirva.ru' . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';

mail($email, $subject, $body, $headers);

// ─── Ответ ────────────────────────────────────────────────────────────────────

echo json_encode([
    'success' => true,
    'data'    => null,
    'message' => '',
    'error'   => null,
]);
