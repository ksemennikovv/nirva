<?php

require_once __DIR__ . '/../services/Database/Database.php';

/**
 * Работа с психоэмоциональным профилем пользователя:
 * profile_parameters, profile_parameter_options,
 * profile_parameter_values, profile_parameter_history, user_memories.
 */
class ProfileParameterRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ─── Справочник параметров ────────────────────────────────────────────────

    /** Все параметры с их options (для fixed-типов). */
    public function getAllParameters(): array
    {
        $stmt = $this->db->query(
            'SELECT id, code, label, description, value_type, category, sort_order
             FROM profile_parameters ORDER BY sort_order'
        );
        $params = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Подгружаем options для fixed-параметров
        $optStmt = $this->db->query(
            'SELECT parameter_id, option_value FROM profile_parameter_options ORDER BY sort_order'
        );
        $allOptions = $optStmt->fetchAll(PDO::FETCH_ASSOC);

        $optMap = [];
        foreach ($allOptions as $opt) {
            $optMap[$opt['parameter_id']][] = $opt['option_value'];
        }

        foreach ($params as &$p) {
            $p['options'] = $optMap[$p['id']] ?? [];
        }

        return $params;
    }

    /** Параметр по code. */
    public function getByCode(string $code): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, code, label, value_type, category FROM profile_parameters WHERE code = ?'
        );
        $stmt->execute([$code]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Допустимые option_value для fixed-параметра. */
    public function getOptions(int $parameterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT option_value FROM profile_parameter_options WHERE parameter_id = ? ORDER BY sort_order'
        );
        $stmt->execute([$parameterId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ─── Значения пользователя ────────────────────────────────────────────────

    /** Все текущие значения параметров для пользователя (массив ['code' => [...объекты...]]). */
    public function getUserValues(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pp.code, ppv.value
             FROM profile_parameter_values ppv
             JOIN profile_parameters pp ON pp.id = ppv.parameter_id
             WHERE ppv.user_id = ?'
        );
        $stmt->execute([$userId]);

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['code']] = json_decode($row['value'], true) ?? [];
        }
        return $result;
    }

    /** Текущее значение одного параметра (массив объектов). */
    public function getUserParameterValue(int $userId, int $parameterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT value FROM profile_parameter_values WHERE user_id = ? AND parameter_id = ?'
        );
        $stmt->execute([$userId, $parameterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (json_decode($row['value'], true) ?? []) : [];
    }

    /** Сохранить (INSERT или UPDATE) значение параметра. */
    public function upsertUserParameterValue(int $userId, int $parameterId, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->prepare(
            'INSERT INTO profile_parameter_values (user_id, parameter_id, value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$userId, $parameterId, $json]);
    }

    // ─── История ─────────────────────────────────────────────────────────────

    public function addHistory(
        int    $userId,
        int    $parameterId,
        string $eventType,   // 'added' | 'removed' | 'updated'
        array  $eventData,
        string $sourceType,  // 'analysis' | 'reflection' | 'diary'
        int    $sourceId
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO profile_parameter_history
                (user_id, parameter_id, event_type, event_data, source_type, source_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $parameterId,
            $eventType,
            json_encode($eventData, JSON_UNESCAPED_UNICODE),
            $sourceType,
            $sourceId,
        ]);
    }

    public function getHistory(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT pph.*, pp.code, pp.label
             FROM profile_parameter_history pph
             JOIN profile_parameters pp ON pp.id = pph.parameter_id
             WHERE pph.user_id = ?
             ORDER BY pph.created_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── user_memories ────────────────────────────────────────────────────────

    public function addMemory(
        int    $userId,
        string $content,
        int    $importanceScore = 5,
        string $sourceType = null,
        int    $sourceId   = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO user_memories (user_id, content, importance_score, source_type, source_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $content, $importanceScore, $sourceType, $sourceId]);
        return (int)$this->db->lastInsertId();
    }

    /** Топ-N воспоминаний по importance_score для подмешивания в промпт. */
    public function getTopParameters(int $userId, int $limit = 6): array
    {
        $stmt = $this->db->prepare(
            'SELECT pp.code, pp.label AS name, ppv.value
             FROM profile_parameter_values ppv
             JOIN profile_parameters pp ON pp.id = ppv.parameter_id
             WHERE ppv.user_id = ? AND ppv.value IS NOT NULL AND ppv.value != "" AND ppv.value != "[]"
             ORDER BY ppv.updated_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTopMemories(int $userId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT content, importance_score, source_type, created_at
             FROM user_memories
             WHERE user_id = ?
             ORDER BY importance_score DESC, created_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
