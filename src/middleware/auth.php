<?php

/**
 * src/middleware/auth.php — проверка авторизации.
 *
 * Подключать в начале каждой защищённой страницы:
 *   require_once __DIR__ . '/../../src/middleware/auth.php';
 *
 * Если пользователь не авторизован — редирект на /login/.
 * Если авторизован — переменная $currentUserId доступна в контексте страницы.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/dashboard/');
    header('Location: /login/?next=' . $redirect);
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
