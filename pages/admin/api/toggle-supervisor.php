<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$current = BusinessConfig::isSupervisorMode();
$new     = $current ? '0' : '1';

$db = Database::getConnection();
$db->prepare(
    'INSERT INTO app_settings (key_name, value, description)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE value = VALUES(value)'
)->execute(['supervisor_mode', $new, 'Режим отладки психолога (supervisor mode)']);

header('Location: /admin/');
exit;
