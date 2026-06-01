<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$id          = (int)($_POST['id'] ?? 0);
$slug        = trim($_POST['slug'] ?? '');
$title       = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$video_url   = trim($_POST['video_url'] ?? '');
$sort_order  = (int)($_POST['sort_order'] ?? 0);

if (!$slug || !$title) {
    header('Location: /admin/practices/?error=' . urlencode('Slug и название обязательны'));
    exit;
}

$db = Database::getConnection();

try {
    if ($id) {
        $stmt = $db->prepare(
            "UPDATE practices SET slug=?, title=?, description=?, video_url=?, sort_order=? WHERE id=?"
        );
        $stmt->execute([$slug, $title, $description ?: null, $video_url ?: null, $sort_order, $id]);
    } else {
        $stmt = $db->prepare(
            "INSERT INTO practices (slug, title, description, video_url, sort_order) VALUES (?,?,?,?,?)"
        );
        $stmt->execute([$slug, $title, $description ?: null, $video_url ?: null, $sort_order]);
    }
    header('Location: /admin/practices/?saved=1');
} catch (\PDOException $e) {
    header('Location: /admin/practices/?error=' . urlencode($e->getMessage()));
}
exit;
