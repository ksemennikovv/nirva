<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$id        = (int)($_POST['id'] ?? 0);
$name      = trim($_POST['name'] ?? '');
$slug      = trim($_POST['slug'] ?? '');
$sortOrder = (int)($_POST['sort_order'] ?? 0);
$desc      = trim($_POST['description'] ?? '');

if (!$name) { http_response_code(400); exit('Bad request'); }

$db = Database::getConnection();

if ($id) {
    $db->prepare("UPDATE meditation_categories SET name=?, slug=?, sort_order=?, description=? WHERE id=?")
       ->execute([$name, $slug ?: null, $sortOrder, $desc ?: null, $id]);
} else {
    $db->prepare("INSERT INTO meditation_categories (name, slug, sort_order, description) VALUES (?,?,?,?)")
       ->execute([$name, $slug ?: null, $sortOrder, $desc ?: null]);
}

header('Location: /admin/meditations/categories.php?saved=1');
