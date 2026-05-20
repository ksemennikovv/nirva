<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

$id               = (int)($_POST['id'] ?? 0);
$plan             = in_array($_POST['plan'] ?? '', ['start','basic','transformation']) ? $_POST['plan'] : null;
$status           = in_array($_POST['status'] ?? '', ['active','cancelled','expired']) ? $_POST['status'] : null;
$analysesPerMonth = isset($_POST['analyses_per_month']) ? max(1, (int)$_POST['analyses_per_month']) : null;
$analysesUsed     = isset($_POST['analyses_used']) ? max(0, (int)$_POST['analyses_used']) : null;
$expiresAt        = $_POST['expires_at'] ?? null;
$redirect         = $_POST['redirect'] ?? '/admin/subscriptions/';

if (!$id) { http_response_code(400); exit('Bad request'); }

$db   = Database::getConnection();
$sets = [];
$vals = [];

if ($plan)             { $sets[] = 'plan = ?';               $vals[] = $plan; }
if ($status)           { $sets[] = 'status = ?';             $vals[] = $status; }
if ($analysesPerMonth !== null) { $sets[] = 'analyses_per_month = ?'; $vals[] = $analysesPerMonth; }
if ($analysesUsed !== null)     { $sets[] = 'analyses_used = ?';      $vals[] = $analysesUsed; }
if ($expiresAt)        { $sets[] = 'expires_at = ?';         $vals[] = $expiresAt; }

if ($sets) {
    $vals[] = $id;
    $db->prepare('UPDATE subscriptions SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
}

header('Location: ' . $redirect);
