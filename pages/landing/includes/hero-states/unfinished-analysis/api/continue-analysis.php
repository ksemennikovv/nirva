<?php
/**
 * api/continue-analysis.php — Возобновляет незавершённый анализ.
 *
 * Вызывается из unfinished-analysis.js (POST).
 * Проверяет наличие незавершённого анализа в сессии
 * и возвращает URL для редиректа в личный кабинет.
 *
 * Ответ: JSON { success: bool, redirect: string }
 */

session_start();
header('Content-Type: application/json');

// Путь к корню: api/ → unfinished-analysis/ → hero-states/ → includes/ → landing/ → pages/ → public_html/
$root = dirname(__DIR__, 6);

require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';

if (!isset($_SESSION['analysis_in_progress'])) {
    echo json_encode(['success' => false, 'error' => 'no_active_analysis']);
    exit;
}

// TODO: получить ID анализа из сессии и передать в редирект
echo json_encode(['success' => true, 'redirect' => '/pages/analysis/']);
