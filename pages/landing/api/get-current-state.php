<?php
/**
 * pages/landing/api/get-current-state.php — Определяет текущий hero-state пользователя.
 *
 * Вызывается из HeroStatesManager.init() при загрузке landing-страницы.
 * Ответ: JSON { success, data: { hero_state } }
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

session_start();
header('Content-Type: application/json');

$root = dirname(__DIR__, 3);

try {
    require_once $root . '/config/app.php';
    require_once $root . '/config/database.php';
    require_once $root . '/src/services/Database/Database.php';
    require_once $root . '/src/repositories/AnalysisRepository.php';

    $heroState = 'default-hero';
    $sessionId = $_SESSION['analysis_session_id'] ?? null;

    if ($sessionId) {
        $analysisRepo = new AnalysisRepository();
        $session      = $analysisRepo->getSession((int) $sessionId);

        if ($session) {
            $heroState = match($session['status']) {
                'active'                                  => 'unfinished-analysis',
                'analysis_completed', 'practice_assigned' => 'registration-gate',
                default                                   => 'default-hero',
            };

            // Восстанавливаем тему из БД в сессию (если сессия была сброшена)
            if (!empty($session['topic'])) {
                $_SESSION['analysis_topic'] = $session['topic'];
            }
        }
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            'hero_state' => $heroState,
            'topic'      => $_SESSION['analysis_topic'] ?? null,
        ],
    ]);

} catch (Throwable $e) {
    error_log('get-current-state.php error: ' . $e->getMessage());
    // При любой ошибке — показываем default-hero (безопасное состояние)
    echo json_encode([
        'success' => true,
        'data'    => ['hero_state' => 'default-hero'],
    ]);
}
