<?php
/**
 * src/repositories/UserRepository.php — CRUD для таблицы users.
 *
 * Зависимости:
 *   - Database::getConnection() (src/services/Database/Database.php)
 *
 * Запрещено:
 *   - бизнес-логика (решения о регистрации, аутентификации и т.д.)
 *   - AI-логика
 *   - вызовы других репозиториев
 */
class UserRepository
{
    private PDO $db;

    /**
     * Получает PDO-соединение через Singleton Database.
     * Соединение создаётся один раз за запрос.
     */
    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /**
     * Создаёт нового пользователя.
     *
     * @param  string $email  Уникальный email; уникальность гарантирована уровнем БД.
     * @return int            ID созданной записи.
     */
    public function create(string $email): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO users (email) VALUES (:email)
        ');

        $stmt->execute([':email' => $email]);

        return (int) $this->db->lastInsertId();
    }

    // ─── Read ─────────────────────────────────────────────────────────────────

    /**
     * Возвращает пользователя по ID.
     *
     * @param  int        $id
     * @return array|null  Строка таблицы или null если не найден.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM users WHERE id = :id LIMIT 1
        ');

        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Возвращает пользователя по email.
     *
     * @param  string     $email
     * @return array|null  Строка таблицы или null если не найден.
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM users WHERE email = :email LIMIT 1
        ');

        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    // ─── Password ─────────────────────────────────────────────────────────────

    public function setPassword(int $id, string $plainPassword): void
    {
        $hash = password_hash($plainPassword, PASSWORD_BCRYPT);
        $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                 ->execute([$hash, $id]);
    }

    public function verifyPassword(int $id, string $plainPassword): bool
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $hash = $stmt->fetchColumn();
        return $hash && password_verify($plainPassword, $hash);
    }

    public function hasPassword(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT password_hash IS NOT NULL FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return (bool)$stmt->fetchColumn();
    }

    // ─── Admin ───────────────────────────────────────────────────────────────

    /** Список всех пользователей с подпиской (для админ-панели). */
    public function getAll(int $limit = 50, int $offset = 0, string $search = ''): array
    {
        if ($search) {
            $stmt = $this->db->prepare("
                SELECT u.id, u.email, u.role, u.created_at,
                       s.plan, s.status AS sub_status, s.expires_at,
                       s.analyses_per_month, s.analyses_used
                FROM users u
                LEFT JOIN subscriptions s ON s.user_id = u.id AND s.status = 'active'
                WHERE u.email LIKE ?
                ORDER BY u.id DESC LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, '%' . $search . '%');
            $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare("
                SELECT u.id, u.email, u.role, u.created_at,
                       s.plan, s.status AS sub_status, s.expires_at,
                       s.analyses_per_month, s.analyses_used
                FROM users u
                LEFT JOIN subscriptions s ON s.user_id = u.id AND s.status = 'active'
                ORDER BY u.id DESC LIMIT ? OFFSET ?
            ");
            $stmt->bindValue(1, $limit,  PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Количество пользователей (для пагинации). */
    public function countAll(string $search = ''): int
    {
        if ($search) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE email LIKE ?");
            $stmt->execute(['%' . $search . '%']);
        } else {
            $stmt = $this->db->query("SELECT COUNT(*) FROM users");
        }
        return (int)$stmt->fetchColumn();
    }

    /** Полный профиль пользователя с агрегатами. */
    public function getWithDetails(int $id): ?array
    {
        $user = $this->findById($id);
        if (!$user) return null;

        $db = $this->db;

        $sub = $db->prepare("SELECT * FROM subscriptions WHERE user_id = ? AND status = 'active' LIMIT 1");
        $sub->execute([$id]);
        $user['subscription'] = $sub->fetch(PDO::FETCH_ASSOC) ?: null;

        $cnt = $db->prepare("SELECT COUNT(*) FROM analysis_sessions WHERE user_id = ?");
        $cnt->execute([$id]);
        $user['analyses_count'] = (int)$cnt->fetchColumn();

        $cnt2 = $db->prepare("SELECT COUNT(*) FROM diary_entries WHERE user_id = ?");
        $cnt2->execute([$id]);
        $user['diary_count'] = (int)$cnt2->fetchColumn();

        $cnt3 = $db->prepare("SELECT COUNT(*) FROM meditations WHERE user_id = ? AND generation_status = 'ready'");
        $cnt3->execute([$id]);
        $user['meditations_count'] = (int)$cnt3->fetchColumn();

        $last = $db->prepare("SELECT created_at FROM analysis_sessions WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $last->execute([$id]);
        $user['last_analysis_at'] = $last->fetchColumn() ?: null;

        return $user;
    }

    public function updateRole(int $id, string $role): void
    {
        $this->db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $id]);
    }

    public function updateEmail(int $id, string $email): void
    {
        $this->db->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$email, $id]);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    /**
     * Удаляет пользователя по ID.
     *
     * @param  int  $id
     * @return bool  true если строка была удалена.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM users WHERE id = :id
        ');

        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
