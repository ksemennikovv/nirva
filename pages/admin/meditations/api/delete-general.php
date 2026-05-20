<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$id = (int)($_POST['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Bad request'); }

$db = Database::getConnection();
$db->prepare("DELETE FROM meditation_purchases WHERE meditation_id = ?")->execute([$id]);
$db->prepare("DELETE FROM meditations WHERE id = ? AND type = 'general'")->execute([$id]);

header('Location: /admin/meditations/general.php?saved=deleted');
