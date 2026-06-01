<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$id = (int)($_POST['id'] ?? 0);
if (!$id) { header('Location: /admin/practices/'); exit; }

$db = Database::getConnection();
try {
    $db->prepare("DELETE FROM practices WHERE id = ?")->execute([$id]);
    header('Location: /admin/practices/?saved=1');
} catch (\PDOException $e) {
    header('Location: /admin/practices/?error=' . urlencode($e->getMessage()));
}
exit;
