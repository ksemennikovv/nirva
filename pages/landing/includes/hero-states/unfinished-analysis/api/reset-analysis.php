<?php
/**
 * unfinished-analysis/api/reset-analysis.php — Сбрасывает незавершённый анализ.
 *
 * Вызывается из unfinished-analysis.js при нажатии "Начать новый".
 * Закрывает текущую сессию в БД (status = 'closed') и очищает PHP-сессию.
 * После ответа JS вызывает HeroStatesManager.showDefaultHero().
 *
 * Ответ: JSON { success }
 */

session_start();
header('Content-Type: application/json');

// Путь к корню: api/ → unfinished-analysis/ → hero-states/ → includes/ → landing/ → pages/ → public_html/
$root = dirname(__DIR__, 6);

require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/AnalysisRepository.php';

// ─── Закрыть сессию в БД ─────────────────────────────────────────────────────

$sessionId = $_SESSION['analysis_session_id'] ?? null;

if ($sessionId) {
    $analysisRepo = new AnalysisRepository();
    $analysisRepo->closeSession((int) $sessionId);
}

// ─── Очистить PHP-сессию ──────────────────────────────────────────────────────

unset(
    $_SESSION['analysis_session_id'],
    $_SESSION['recommended_practice']
);

// ─── Ответ ────────────────────────────────────────────────────────────────────

echo json_encode(['success' => true]);
