<?php

require_once __DIR__ . '/../../repositories/MeditationRepository.php';
require_once __DIR__ . '/../../repositories/AppSettingsRepository.php';
require_once __DIR__ . '/../../../config/business.php';
require_once __DIR__ . '/../../../config/services.php';
require_once __DIR__ . '/../ImageGeneration/ImageGenerationService.php';

/**
 * Управление генерацией и жизненным циклом персональных медитаций.
 *
 * Триггер: ProfileService::updateProfile() → если изменения → scheduleGeneration()
 * Генерация: фоновый процесс src/scripts/process-meditations.php
 */
class MeditationService
{
    private MeditationRepository $repo;
    private AppSettingsRepository $settings;

    public function __construct()
    {
        $this->repo     = new MeditationRepository();
        $this->settings = new AppSettingsRepository();
    }

    /**
     * Планирует генерацию персональных медитаций и запускает фоновый воркер.
     * Количество определяется константой MEDITATION_GENERATE_COUNT.
     * Если MEDITATION_AUTO_GENERATE !== 'yes' — ничего не делает.
     */
    public function scheduleGeneration(int $userId, string $sourceType, int $sourceId): void
    {
        if (!BusinessConfig::meditationAutoGenerate()) {
            return;
        }

        $count = BusinessConfig::meditationGenerateCount();
        $expiresAt = $this->computeExpiresAt();

        for ($i = 0; $i < $count; $i++) {
            $this->repo->create([
                'user_id'           => $userId,
                'type'              => 'personal',
                'topic_type'        => 'user_specific',
                'generation_status' => 'pending',
                'expires_at'        => $expiresAt,
                'analysis_id'       => ($sourceType === 'analysis' || $sourceType === 'reflection') ? $sourceId : null,
            ]);
        }

        $this->queueGenerationJob($userId, $sourceType, $sourceId);
        $this->launchBackgroundWorker();
    }

    /**
     * Обрабатывает одну pending-медитацию.
     * Вызывается из фонового скрипта или setup-скриптов.
     */
    public function processNextPending(): bool
    {
        $pending = $this->repo->getPending(1);
        if (empty($pending)) return false;

        $meditation = $pending[0];
        $this->generateOne((int)$meditation['id']);
        return true;
    }

    public function generateOne(int $meditationId): void
    {
        $meditation = $this->repo->getById($meditationId);
        if (!$meditation) return;

        $this->repo->updateStatus($meditationId, 'generating');

        try {
            // 1. Получить текст медитации от AI
            [$title, $description, $personalContext, $topic] = $this->generateTextFromAI($meditation);

            // 2. Отправить на генерацию аудио (ElevenLabs синхронный — файл сохраняется сразу)
            $jobId = $this->sendToAudioProvider($title, $description, $personalContext, $meditationId);

            $this->repo->updateStatus($meditationId, 'generating', [
                'title'               => $title,
                'description'         => $description,
                'personal_context'    => $personalContext,
                'topic'               => $topic,
                'generation_job_id'   => $jobId,
                'generation_provider' => MEDITATION_AUDIO_PROVIDER,
            ]);

            // 3. Сразу проверить статус (ElevenLabs синхронный — файл уже готов)
            $this->checkAudioStatus($meditationId);

        } catch (Throwable $e) {
            error_log("MeditationService::generateOne($meditationId) error: " . $e->getMessage());
            $this->repo->updateStatus($meditationId, 'failed');
        }
    }

    /**
     * Проверяет статус задания у аудио-сервиса.
     * При готовности сохраняет URL и меняет статус на 'ready'.
     */
    public function checkAudioStatus(int $meditationId): void
    {
        $meditation = $this->repo->getById($meditationId);
        if (!$meditation || empty($meditation['generation_job_id'])) return;

        try {
            $result = $this->fetchAudioStatus($meditation['generation_job_id']);

            if ($result['status'] === 'done') {
                // Генерация изображения после готовности аудио
                $imageUrl = null;
                try {
                    $med      = $this->repo->getById($meditationId);
                    $provider = ($med['type'] ?? 'personal') === 'personal'
                        ? BusinessConfig::imageProviderPersonal()
                        : BusinessConfig::imageProviderGeneral();

                    if ($provider !== 'none' && $provider) {
                        $category = '';
                        if (!empty($med['category_id'])) {
                            $db  = \Database::getConnection();
                            $row = $db->prepare("SELECT name FROM meditation_categories WHERE id = ?")->execute([(int)$med['category_id']]);
                            $cat = $db->query("SELECT name FROM meditation_categories WHERE id = " . (int)$med['category_id'])->fetch(\PDO::FETCH_ASSOC);
                            $category = $cat['name'] ?? '';
                        }
                        $imageUrl = ImageGenerationService::generate($provider, $med, $category);
                    }
                } catch (\Throwable $imgErr) {
                    error_log("MeditationService: image generation failed for #$meditationId: " . $imgErr->getMessage());
                }

                $this->repo->updateStatus($meditationId, 'ready', [
                    'full_audio_url' => $result['audio_url'],
                    'demo_audio_url' => $result['demo_url'] ?? null,
                    'image_url'      => $imageUrl,
                ]);
            } elseif ($result['status'] === 'failed') {
                $this->repo->updateStatus($meditationId, 'failed');
            }
        } catch (Throwable $e) {
            error_log("MeditationService::checkAudioStatus($meditationId) error: " . $e->getMessage());
        }
    }

    // ─── Приватные методы ─────────────────────────────────────────────────────

    private function computeExpiresAt(): ?string
    {
        $rule = $this->settings->get('meditation_expiry_rule', 'days');

        if ($rule === 'days') {
            $days = (int)$this->settings->get('meditation_expiry_days', 30);
            return date('Y-m-d H:i:s', strtotime("+{$days} days"));
        }

        return null;
    }

    private function generateTextFromAI(array $meditation): array
    {
        require_once __DIR__ . '/../../services/AI/AIService.php';
        require_once __DIR__ . '/../../services/Profile/ProfileService.php';
        require_once __DIR__ . '/../../repositories/AnalysisRepository.php';

        $userId  = (int)$meditation['user_id'];
        $profile = '';

        if ($userId) {
            $profileService = new ProfileService();
            $profile        = $profileService->formatForPrompt($userId);
        }

        $analysisContext = '';
        if (!empty($meditation['analysis_id'])) {
            $analysisRepo = new AnalysisRepository();
            $analysis     = $analysisRepo->getSession((int)$meditation['analysis_id']);
            if ($analysis) {
                $parts = [];
                if (!empty($analysis['topic']))              $parts[] = 'Тема разбора: ' . $analysis['topic'];
                if (!empty($analysis['analysis_summary']))   $parts[] = 'Итог разбора: ' . $analysis['analysis_summary'];
                if (!empty($analysis['reflection_summary'])) $parts[] = 'Самоисследование: ' . $analysis['reflection_summary'];
                if (!empty($analysis['selected_practice']))  $parts[] = 'Практика: ' . $analysis['selected_practice'];
                if (!empty($analysis['personal_task']))      $parts[] = 'Персональное слово клиента: ' . $analysis['personal_task'];
                $analysisContext = implode("\n", $parts);
            }
        }

        $context = trim($analysisContext . ($profile ? "\n\n" . $profile : ''));

        $ai  = new AIService();
        $raw = $ai->sendWithPrompt([], 'meditation-generation-prompt.txt', $context);

        $parsed = json_decode(trim($raw), true);

        if (!$parsed) {
            throw new RuntimeException('AI returned invalid JSON for meditation');
        }

        return [
            $parsed['title']            ?? 'Персональная медитация',
            $parsed['description']      ?? '',
            $parsed['personal_context'] ?? '',
            $parsed['topic']            ?? '',
        ];
    }

    private function sendToAudioProvider(string $title, string $description, string $personalContext, int $meditationId): string
    {
        if (!defined('MEDITATION_AUDIO_API_KEY') || !MEDITATION_AUDIO_API_KEY) {
            return 'dev_job_' . uniqid();
        }

        require_once __DIR__ . '/../../services/ElevenLabs/ElevenLabsService.php';

        $el  = new ElevenLabsService();
        $url = $el->generateSpeech($personalContext, $meditationId);
        $url = $el->applyMusicOverlay($url);

        return 'saved:' . $meditationId;
    }

    private function fetchAudioStatus(string $jobId): array
    {
        // Файл уже сохранён ElevenLabsService::generateSpeech()
        if (str_starts_with($jobId, 'saved:')) {
            $id = (int)str_replace('saved:', '', $jobId);
            return [
                'status'    => 'done',
                'audio_url' => '/assets/audio/meditations/' . $id . '.mp3',
                'demo_url'  => null,
            ];
        }

        // Dev-режим — без реального API
        if (str_starts_with($jobId, 'dev_job_')) {
            return [
                'status'    => 'done',
                'audio_url' => '/assets/audio/sample.mp3',
                'demo_url'  => '/assets/audio/sample-demo.mp3',
            ];
        }

        // Устаревший async-формат (на случай старых записей в БД)
        $ch = curl_init(MEDITATION_AUDIO_API_URL . '/status/' . urlencode($jobId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['xi-api-key: ' . MEDITATION_AUDIO_API_KEY],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno) throw new RuntimeException('Audio status cURL error: ' . $errno);

        $data = json_decode($raw, true);
        return [
            'status'    => $data['status']    ?? 'pending',
            'audio_url' => $data['audio_url'] ?? null,
            'demo_url'  => $data['demo_url']  ?? null,
        ];
    }

    /**
     * Запускает фоновый PHP-процесс для обработки очереди медитаций.
     */
    private function launchBackgroundWorker(): void
    {
        $script = dirname(__DIR__, 2) . '/scripts/process-meditations.php';

        if (!file_exists($script)) {
            error_log('MeditationService: worker script not found: ' . $script);
            return;
        }

        $php = PHP_BINARY ?: 'php';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen('start /B "' . $php . '" "' . $script . '"', 'r'));
        } else {
            exec('"' . $php . '" "' . $script . '" > /dev/null 2>&1 &');
        }
    }

    private function queueGenerationJob(int $userId, string $sourceType, int $sourceId): void
    {
        try {
            $db = Database::getConnection();
            $db->prepare(
                'INSERT INTO background_jobs (type, payload) VALUES (?, ?)'
            )->execute([
                'generate_meditations',
                json_encode(['user_id' => $userId, 'source_type' => $sourceType, 'source_id' => $sourceId]),
            ]);
        } catch (Throwable $e) {
            error_log('MeditationService::queueGenerationJob error: ' . $e->getMessage());
        }
    }
}
