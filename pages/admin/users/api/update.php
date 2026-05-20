<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/repositories/UserRepository.php';

$id    = (int)($_POST['id'] ?? 0);
$email = trim($_POST['email'] ?? '');
$role  = in_array($_POST['role'] ?? '', ['user', 'admin']) ? $_POST['role'] : 'user';

if (!$id || !$email) { http_response_code(400); exit('Bad request'); }

$repo = new UserRepository();
$repo->updateEmail($id, $email);
$repo->updateRole($id, $role);

header('Location: /admin/users/view.php?id=' . $id . '&saved=1');
