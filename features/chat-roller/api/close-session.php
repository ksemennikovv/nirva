<?php
/**
 * api/close-session.php — Завершает сессию чата.
 *
 * Вызывается из chat-roller.js при ChatRoller.close().
 * Сохраняет финальное состояние диалога и готовит данные
 * для формирования практики (registration-gate).
 *
 * Ответ: JSON { success: bool }
 */

session_start();
header('Content-Type: application/json');

// Путь к корню: api/ → chat-roller/ → features/ → public_html/
$root = dirname(__DIR__, 3);

require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';

// TODO: зафиксировать analysis_complete в сессии/БД
// TODO: определить recommended_practice по итогам диалога
$_SESSION['analysis_complete'] = true;

echo json_encode(['success' => true]);
