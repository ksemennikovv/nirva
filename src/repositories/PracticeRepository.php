<?php
/**
 * src/repositories/PracticeRepository.php — CRUD для таблицы practice_access.
 *
 * Управляет доступами пользователей к практикам в личном кабинете.
 *
 * Зависимости:
 *   - Database::getConnection() (src/services/Database/Database.php)
 *
 * Запрещено:
 *   - решать, какую практику выдать (это AI-логика / бизнес-логика)
 *   - работа с другими таблицами
 */
class PracticeRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /**
     * Выдаёт пользователю доступ к практике.
     *
     * @param  int    $userId      ID пользователя.
     * @param  string $practiceId  Идентификатор практики.
     * @return int                 ID созданной записи.
     */
    public function create(int $userId, string $practiceId): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO practice_access (user_id, practice_id)
            VALUES (:user_id, :practice_id)
        ');

        $stmt->execute([
            ':user_id'     => $userId,
            ':practice_id' => $practiceId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    // ─── Read ─────────────────────────────────────────────────────────────────

    /**
     * Возвращает все практики пользователя.
     *
     * @param  int   $userId
     * @return array  Массив строк [ ['id', 'practice_id', 'granted_at'], ... ]
     */
    public function findByUser(int $userId): array
    {
        $stmt = $this->db->prepare('
            SELECT id, practice_id, granted_at
            FROM practice_access
            WHERE user_id = :user_id
            ORDER BY granted_at DESC
        ');

        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Проверяет наличие доступа пользователя к конкретной практике.
     *
     * @param  int        $userId
     * @param  string     $practiceId
     * @return array|null  Строка таблицы или null если доступа нет.
     */
    public function findByUserAndPractice(int $userId, string $practiceId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM practice_access
            WHERE user_id = :user_id AND practice_id = :practice_id
            LIMIT 1
        ');

        $stmt->execute([
            ':user_id'     => $userId,
            ':practice_id' => $practiceId,
        ]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    /**
     * Удаляет доступ по ID записи.
     *
     * @param  int  $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM practice_access WHERE id = :id
        ');

        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
