<?php
/**
 * src/repositories/AuthCodeRepository.php — CRUD для таблицы verification_codes.
 *
 * Зависимости:
 *   - Database::getConnection() (src/services/Database/Database.php)
 *
 * Запрещено:
 *   - генерировать коды (это бизнес-логика)
 *   - отправлять email
 *   - решать, истёк ли код — только читать данные
 */
class AuthCodeRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /**
     * Сохраняет код верификации для указанного email.
     *
     * @param  string $email      Email получателя.
     * @param  string $code       Код подтверждения (генерируется выше по стеку).
     * @param  string $expiresAt  Дата истечения в формате 'Y-m-d H:i:s'.
     * @return int                ID созданной записи.
     */
    public function create(string $email, string $code, string $expiresAt): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO verification_codes (email, code, expires_at)
            VALUES (:email, :code, :expires_at)
        ');

        $stmt->execute([
            ':email'      => $email,
            ':code'       => $code,
            ':expires_at' => $expiresAt,
        ]);

        return (int) $this->db->lastInsertId();
    }

    // ─── Read ─────────────────────────────────────────────────────────────────

    /**
     * Ищет активный (не использованный) код для email.
     * Возвращает последний по времени создания.
     *
     * @param  string     $email
     * @param  string     $code
     * @return array|null  Строка таблицы или null.
     */
    public function findActiveByEmailAndCode(string $email, string $code): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM verification_codes
            WHERE email   = :email
              AND code    = :code
              AND used_at IS NULL
            ORDER BY created_at DESC
            LIMIT 1
        ');

        $stmt->execute([':email' => $email, ':code' => $code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Возвращает последний код для email (любой статус).
     * Используется для rate limit в resend-code.php.
     *
     * @param  string     $email
     * @return array|null  Строка таблицы или null если не найдена.
     */
    public function findLastByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM verification_codes
            WHERE email = :email
            ORDER BY created_at DESC
            LIMIT 1
        ');

        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    /**
     * Помечает код как использованный (устанавливает used_at = NOW()).
     *
     * @param  int  $id  ID записи verification_codes.
     * @return bool
     */
    public function markAsUsed(int $id): bool
    {
        $stmt = $this->db->prepare('
            UPDATE verification_codes
            SET used_at = NOW()
            WHERE id = :id
        ');

        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    /**
     * Удаляет все истёкшие коды (expires_at < NOW()).
     * Вызывается периодически для очистки таблицы.
     *
     * @return int Количество удалённых строк.
     */
    public function deleteExpired(): int
    {
        $stmt = $this->db->prepare('
            DELETE FROM verification_codes WHERE expires_at < NOW()
        ');

        $stmt->execute();

        return $stmt->rowCount();
    }
}
