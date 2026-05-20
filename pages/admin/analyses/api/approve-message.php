<?php
session_start();
$root = dirname(__DIR__, 4);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/repositories/MessageRepository.php';

$id      = (int)($_POST['id'] ?? 0);
$content = trim($_POST['content'] ?? '');
if (!$id) { http_response_code(400); exit('Bad request'); }

$msgRepo = new MessageRepository();
$msg     = $msgRepo->getLastAssistantMessage(0); // получим по id напрямую

// Получаем сообщение по id
$db  = Database::getConnection();
$row = $db->prepare('SELECT * FROM messages WHERE id = ?');
$row->execute([$id]);
$msg = $row->fetch(PDO::FETCH_ASSOC);
if (!$msg) { http_response_code(404); exit('Not found'); }

$sessionId = (int)$msg['analysis_session_id'];

// Одобрить (с возможной правкой текста)
$editedContent = ($content && $content !== $msg['content']) ? $content : null;
$msgRepo->approveMessage($id, $editedContent);

$redirect = $_POST['redirect'] ?? '/admin/analyses/supervise.php';
header('Location: ' . $redirect);
