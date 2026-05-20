<?php
/**
 * registration-gate/api/verify-code.php — Проверяет код верификации email.
 *
 * Вызывается из RegistrationGate.handleVerifyRegistrationCode() (POST, JSON).
 *
 * Flow:
 *   1. Получить code из тела + email из $_SESSION['pending_email']
 *   2. Найти активный код через AuthCodeRepository
 *   3. Проверить срок действия
 *   4. Пометить как использованный
 *   5. Создать или найти пользователя
 *   6. Привязать анонимную сессию анализа к пользователю
 *   7. Выдать доступ к практике
 *   8. Записать user_id в PHP-сессию
 *
 * Ответ: JSON { success, data: { redirect }, message, error }
 */

session_start();
header('Content-Type: application/json');

// Путь к корню: api/ → registration-gate/ → hero-states/ → includes/ → landing/ → pages/ → public_html/
$root = dirname(__DIR__, 6);

require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/AuthCodeRepository.php';
require_once $root . '/src/repositories/UserRepository.php';
require_once $root . '/src/repositories/AnalysisRepository.php';
require_once $root . '/src/repositories/PracticeRepository.php';

// ─── Входящие данные ──────────────────────────────────────────────────────────

$body  = json_decode(file_get_contents('php://input'), true);
$code  = trim($body['code'] ?? '');
$email = $_SESSION['pending_email'] ?? null;

if (!$code) {
    echo json_encode([
        'success' => false,
        'data'    => null,
        'message' => 'Введите код',
        'error'   => 'empty_code',
    ]);
    exit;
}

if (!$email) {
    echo json_encode([
        'success' => false,
        'data'    => null,
        'message' => 'Сессия истекла. Начните заново.',
        'error'   => 'no_pending_email',
    ]);
    exit;
}

// ─── Найти и проверить код ────────────────────────────────────────────────────

$authCodeRepo = new AuthCodeRepository();
$record       = $authCodeRepo->findActiveByEmailAndCode($email, $code);

if (!$record) {
    echo json_encode([
        'success' => false,
        'data'    => null,
        'message' => 'Неверный или использованный код',
        'error'   => 'invalid_code',
    ]);
    exit;
}

if (strtotime($record['expires_at']) < time()) {
    echo json_encode([
        'success' => false,
        'data'    => null,
        'message' => 'Код истёк. Запросите новый.',
        'error'   => 'code_expired',
    ]);
    exit;
}

$authCodeRepo->markAsUsed((int) $record['id']);

// ─── Создать или найти пользователя ───────────────────────────────────────────

$userRepo = new UserRepository();
$user     = $userRepo->findByEmail($email);

if ($user) {
    $userId = (int) $user['id'];
} else {
    $userId = $userRepo->create($email);
}

// ─── Привязать анонимную сессию к пользователю ───────────────────────────────

$sessionId = $_SESSION['analysis_session_id'] ?? null;

if ($sessionId) {
    $analysisRepo = new AnalysisRepository();
    $analysisRepo->assignUser((int) $sessionId, $userId);
}

// ─── Выдать доступ к практике ─────────────────────────────────────────────────

$practiceTitle = $_SESSION['recommended_practice'] ?? null;

if ($practiceTitle) {
    $practiceRepo = new PracticeRepository();

    // Проверяем, нет ли уже доступа (повторный вход существующего пользователя)
    $existing = $practiceRepo->findByUserAndPractice($userId, $practiceTitle);
    if (!$existing) {
        $practiceRepo->create($userId, $practiceTitle);
    }
}

// ─── Обновить PHP-сессию ──────────────────────────────────────────────────────

$_SESSION['user_id']            = $userId;
$_SESSION['registration_step']  = 'password'; // ожидаем создание пароля
unset($_SESSION['pending_email']);

// Проверяем: у пользователя уже есть пароль (повторная верификация существующего)?
$needPassword = !$userRepo->hasPassword($userId);

// ─── Ответ ────────────────────────────────────────────────────────────────────

echo json_encode([
    'success' => true,
    'data'    => [
        'need_password' => $needPassword,
        'redirect'      => $needPassword ? null : '/dashboard/',
    ],
    'message' => '',
    'error'   => null,
]);
