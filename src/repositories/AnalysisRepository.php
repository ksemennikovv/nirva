<?php
/**
 * src/repositories/AnalysisRepository.php — CRUD для таблицы analysis_sessions.
 *
 * Зависимости:
 *   - Database::getConnection() (src/services/Database/Database.php)
 *
 * Запрещено:
 *   - бизнес-логика (решать, когда открывать/закрывать сессию)
 *   - AI-логика
 *   - работа с таблицей messages (это MessageRepository)
 */
class AnalysisRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /**
     * Создаёт новую сессию анализа.
     *
     * @param  int|null $userId  ID пользователя; null — анонимная сессия.
     * @return int               ID созданной сессии.
     */
    public function createSession(?int $userId = null): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO analysis_sessions (user_id) VALUES (:user_id)
        ');

        $stmt->execute([':user_id' => $userId]);

        return (int) $this->db->lastInsertId();
    }

    // ─── Read ─────────────────────────────────────────────────────────────────

    /**
     * Возвращает сессию по ID.
     *
     * @param  int        $sessionId
     * @return array|null  Строка таблицы или null если не найдена.
     */
    public function getSession(int $sessionId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM analysis_sessions WHERE id = :id LIMIT 1
        ');

        $stmt->execute([':id' => $sessionId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Возвращает активную сессию пользователя.
     * Используется для определения состояния unfinished-analysis.
     *
     * @param  int        $userId
     * @return array|null
     */
    public function getActiveSessionByUser(int $userId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM analysis_sessions
            WHERE user_id = :user_id AND status = \'active\'
            ORDER BY created_at DESC
            LIMIT 1
        ');

        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    /**
     * Обновляет current_state сессии.
     * current_state отражает текущий hero-state на landing-странице.
     *
     * Допустимые значения (определены в HeroStatesManager):
     *   'default-hero' | 'unfinished-analysis' | 'registration-gate'
     *
     * @param  int    $sessionId
     * @param  string $state
     * @return bool
     */
    public function updateState(int $sessionId, string $state): bool
    {
        $stmt = $this->db->prepare('
            UPDATE analysis_sessions
            SET current_state = :state
            WHERE id = :id
        ');

        $stmt->execute([':state' => $state, ':id' => $sessionId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Обновляет тему разбора (topic).
     * Вызывается из send-message.php при обнаружении [TOPIC_UPDATE] в ответе AI.
     *
     * @param  int    $sessionId
     * @param  string $topic
     * @return bool
     */
    public function updateTopic(int $sessionId, string $topic): bool
    {
        $stmt = $this->db->prepare('
            UPDATE analysis_sessions
            SET topic = :topic
            WHERE id = :id
        ');

        $stmt->execute([':topic' => $topic, ':id' => $sessionId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Обновляет selected_practice — практику, выбранную по итогам анализа.
     *
     * @param  int    $sessionId
     * @param  string $practice
     * @return bool
     */
    public function updatePractice(int $sessionId, string $practice): bool
    {
        $stmt = $this->db->prepare('
            UPDATE analysis_sessions
            SET selected_practice = :practice
            WHERE id = :id
        ');

        $stmt->execute([':practice' => $practice, ':id' => $sessionId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Привязывает анонимную сессию к зарегистрировавшемуся пользователю.
     *
     * @param  int $sessionId
     * @param  int $userId
     * @return bool
     */
    public function assignUser(int $sessionId, int $userId): bool
    {
        $stmt = $this->db->prepare('
            UPDATE analysis_sessions
            SET user_id = :user_id
            WHERE id = :id
        ');

        $stmt->execute([':user_id' => $userId, ':id' => $sessionId]);

        return $stmt->rowCount() > 0;
    }

    // ─── Close / Complete ─────────────────────────────────────────────────────

    /**
     * Закрывает сессию анализа (status = 'closed').
     * Опционально сохраняет выбранную практику.
     *
     * @param  int         $sessionId
     * @param  string|null $selectedPractice
     * @return bool
     */
    public function closeSession(int $sessionId, ?string $selectedPractice = null): bool
    {
        $stmt = $this->db->prepare('
            UPDATE analysis_sessions
            SET status            = \'closed\',
                selected_practice = COALESCE(:practice, selected_practice)
            WHERE id = :id
        ');

        $stmt->execute([':practice' => $selectedPractice, ':id' => $sessionId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Завершает AI-разбор (analysis_completed → practice_assigned).
     * Сохраняет практику, summary, personal_task.
     */
    public function completeSession(
        int     $sessionId,
        ?string $selectedPractice = null,
        ?string $analysisSummary  = null,
        ?string $personalTask     = null
    ): bool {
        $stmt = $this->db->prepare('
            UPDATE analysis_sessions
            SET status            = \'analysis_completed\',
                current_state     = \'registration-gate\',
                selected_practice = COALESCE(:practice, selected_practice),
                analysis_summary  = COALESCE(:summary,  analysis_summary),
                personal_task     = COALESCE(:task,     personal_task)
            WHERE id = :id
        ');

        $stmt->execute([
            ':practice' => $selectedPractice,
            ':summary'  => $analysisSummary,
            ':task'     => $personalTask,
            ':id'       => $sessionId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /** Отмечает практику как выполненную. */
    public function markPracticeCompleted(int $sessionId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE analysis_sessions SET status = 'practice_completed' WHERE id = ?"
        );
        $stmt->execute([$sessionId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Завершает самоисследование (reflection → completed).
     * Сохраняет reflection_summary, final_recommendations, completed_at.
     */
    public function completeReflection(
        int     $sessionId,
        ?string $reflectionSummary    = null,
        ?string $finalRecommendations = null
    ): bool {
        $stmt = $this->db->prepare('
            UPDATE analysis_sessions
            SET status                 = \'completed\',
                reflection_summary     = COALESCE(:reflection, reflection_summary),
                final_recommendations  = COALESCE(:recommendations, final_recommendations),
                completed_at           = NOW()
            WHERE id = :id
        ');

        $stmt->execute([
            ':reflection'       => $reflectionSummary,
            ':recommendations'  => $finalRecommendations,
            ':id'               => $sessionId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /** Все разборы пользователя (для архива и dashboard). */
    public function getUserSessions(int $userId, ?string $statusFilter = null): array
    {
        if ($statusFilter) {
            $stmt = $this->db->prepare(
                'SELECT * FROM analysis_sessions WHERE user_id = ? AND status = ? ORDER BY created_at DESC'
            );
            $stmt->execute([$userId, $statusFilter]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM analysis_sessions WHERE user_id = ? ORDER BY created_at DESC'
            );
            $stmt->execute([$userId]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Последний завершённый разбор пользователя. */
    public function getLastCompleted(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM analysis_sessions WHERE user_id = ? AND status = 'completed' ORDER BY completed_at DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Количество завершённых разборов пользователя (для онбординг-видео). */
    public function countCompleted(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM analysis_sessions WHERE user_id = ? AND status = 'completed'"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
}
