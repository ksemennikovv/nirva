<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/repositories/UserRepository.php';

$id = (int)($_POST['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Bad request'); }

// Нельзя удалить самого себя
if ($id === (int)($adminUser['id'] ?? 0)) {
    die('Нельзя удалить собственный аккаунт.');
}

$repo = new UserRepository();
$repo->delete($id);

header('Location: /admin/users/?deleted=1');
