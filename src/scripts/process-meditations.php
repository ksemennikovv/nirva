<?php
/**
 * src/scripts/process-meditations.php
 *
 * CLI-скрипт обработки очереди pending-медитаций.
 * Запускается в фоне из MeditationService::launchBackgroundWorker()
 * или вручную: php src/scripts/process-meditations.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/ai.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/MeditationRepository.php';
require_once $root . '/src/repositories/AppSettingsRepository.php';
require_once $root . '/src/services/Meditation/MeditationService.php';

$service   = new MeditationService();
$processed = 0;

while ($service->processNextPending()) {
    $processed++;
    error_log("process-meditations.php: processed meditation #{$processed}");
}

error_log("process-meditations.php: done, total processed = {$processed}");
