<?php
/**
 * src/middleware/admin.php
 *
 * Проверяет что текущий пользователь имеет роль 'admin'.
 * Подключать после session_start() и Database.
 * Устанавливает $adminUser.
 */

if (!class_exists('Database')) {
    require_once dirname(__DIR__, 2) . '/src/services/Database/Database.php';
}

$adminUserId = (int)($_SESSION['user_id'] ?? 0);

if (!$adminUserId) {
    $next = urlencode($_SERVER['REQUEST_URI'] ?? '/admin/');
    header('Location: /login/?next=' . $next);
    exit;
}

$_adminStmt = Database::getConnection()->prepare('SELECT id, email, role FROM users WHERE id = ? LIMIT 1');
$_adminStmt->execute([$adminUserId]);
$adminUser = $_adminStmt->fetch(PDO::FETCH_ASSOC);

if (!$adminUser || $adminUser['role'] !== 'admin') {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>403</title></head><body>';
    echo '<h2>403 — Доступ запрещён</h2><p>Эта страница доступна только администраторам.</p>';
    echo '<p><a href="/dashboard/">← На главную</a></p></body></html>';
    exit;
}
