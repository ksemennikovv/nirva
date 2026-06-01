<?php
/**
 * src/repositories/MessageRepository.php — CRUD для таблицы messages.
 *
 * Зависимости:
 *   - Database::getConnection() (src/services/Database/Database.php)
 *
 * Запрещено:
 *   - бизнес-логика (форматирование, фильтрация по смыслу)
 *   - AI-логика (генерация ответов)
 *   - работа с другими таблицами
 */
class MessageRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /**
     * Сохраняет сообщение в таблицу messages.
     *
     * @param  int    $sessionId  ID сессии анализа (analysis_sessions.id).
     * @param  string $role       'user' или 'assistant'.
     * @param  string $content    Текст сообщения.
     * @return int                ID созданной записи.
     */
    public function saveMessage(int $sessionId, string $role, string $content, string $phase = 'analysis'): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO messages (analysis_session_id, role, content, phase)
            VALUES (:session_id, :role, :content, :phase)
        ');

        $stmt->execute([
            ':session_id' => $sessionId,
            ':role'       => $role,
            ':content'    => $content,
            ':phase'      => $phase,
        ]);

        return (int) $this->db->lastInsertId();
    }

    // ─── Read ─────────────────────────────────────────────────────────────────

    /**
     * Возвращает все сообщения сессии в хронологическом порядке.
     * Используется для:
     *   - восстановления истории в ChatRoller при перезагрузке
     *   - передачи контекста в AI-сервис
     *
     * @param  int   $sessionId
     * @return array  Массив строк [ ['id', 'role', 'content', 'created_at'], ... ]
     */
    public function getMessages(int $sessionId, ?string $phase = null): array
    {
        if ($phase !== null) {
            $stmt = $this->db->prepare('
                SELECT id, role, content, phase, created_at
                FROM messages
                WHERE analysis_session_id = :session_id AND phase = :phase
                ORDER BY created_at ASC, id ASC
            ');
            $stmt->execute([':session_id' => $sessionId, ':phase' => $phase]);
        } else {
            $stmt = $this->db->prepare('
                SELECT id, role, content, phase, created_at
                FROM messages
                WHERE analysis_session_id = :session_id
                ORDER BY created_at ASC, id ASC
            ');
            $stmt->execute([':session_id' => $sessionId]);
        }

        return $stmt->fetchAll();
    }

    /**
     * Возвращает последние N сообщений сессии.
     * Используется для передачи короткого контекста в AI без полной истории.
     *
     * @param  int $sessionId
     * @param  int $limit      Количество последних сообщений.
     * @return array
     */
    public function getLastMessages(int $sessionId, int $limit): array
    {
        $stmt = $this->db->prepare('
            SELECT id, role, content, created_at
            FROM messages
            WHERE analysis_session_id = :session_id
            ORDER BY created_at DESC, id DESC
            LIMIT :limit
        ');

        $stmt->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $stmt->bindValue(':limit',      $limit,     PDO::PARAM_INT);
        $stmt->execute();

        // Разворачиваем: запрос возвращает DESC, нужен ASC для контекста
        return array_reverse($stmt->fetchAll());
    }

    // ─── Supervisor Mode ──────────────────────────────────────────────────────

    /** Пометить сообщение ассистента как ожидающее проверки. */
    public function markPendingReview(int $messageId): void
    {
        $this->db->prepare(
            'UPDATE messages SET review_status = "pending_review" WHERE id = ?'
        )->execute([$messageId]);
    }

    /** Последнее сообщение ассистента в сессии (любой статус). */
    public function getLastAssistantMessage(int $sessionId, string $phase = 'analysis'): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM messages
             WHERE analysis_session_id = ? AND role = "assistant" AND phase = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$sessionId, $phase]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Только одобренные сообщения — для передачи в AI как контекст.
     * Исключает rejected и pending_review ответы ассистента.
     */
    public function getApprovedMessages(int $sessionId, string $phase = 'analysis'): array
    {
        // review_status IS NULL — сообщения до миграции 006, считаем approved
        $stmt = $this->db->prepare(
            'SELECT id, role,
                    COALESCE(reviewed_content, content) AS content,
                    phase, created_at
             FROM messages
             WHERE analysis_session_id = ?
               AND phase = ?
               AND (role = "user" OR review_status = "approved" OR review_status IS NULL)
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$sessionId, $phase]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Все сообщения с полными данными о статусе — для вида отладки в админке.
     */
    public function getAllWithReviewStatus(int $sessionId, string $phase = 'analysis'): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, sc.instruction AS supervisor_instruction, sc.id AS correction_id
             FROM messages m
             LEFT JOIN supervisor_corrections sc ON sc.rejected_msg_id = m.id
             WHERE m.analysis_session_id = ? AND m.phase = ?
             ORDER BY m.created_at ASC, m.id ASC'
        );
        $stmt->execute([$sessionId, $phase]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Одобрить сообщение (с возможной заменой текста психологом). */
    public function approveMessage(int $messageId, ?string $reviewedContent = null): void
    {
        if ($reviewedContent !== null) {
            $this->db->prepare(
                'UPDATE messages SET review_status="approved", reviewed_content=?, reviewed_at=NOW() WHERE id=?'
            )->execute([$reviewedContent, $messageId]);
        } else {
            $this->db->prepare(
                'UPDATE messages SET review_status="approved", reviewed_at=NOW() WHERE id=?'
            )->execute([$messageId]);
        }
    }

    /** Отклонить сообщение ассистента. */
    public function rejectMessage(int $messageId): void
    {
        $this->db->prepare(
            'UPDATE messages SET review_status="rejected", reviewed_at=NOW() WHERE id=?'
        )->execute([$messageId]);
    }
}
