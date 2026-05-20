<?php

require_once __DIR__ . '/../services/Database/Database.php';

class DiaryRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    // ─── diary_entries ────────────────────────────────────────────────────────

    public function createEntry(int $userId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO diary_entries (user_id) VALUES (?)'
        );
        $stmt->execute([$userId]);
        return (int)$this->db->lastInsertId();
    }

    public function updateSummary(int $entryId, string $summary): void
    {
        $stmt = $this->db->prepare(
            'UPDATE diary_entries SET summary = ? WHERE id = ?'
        );
        $stmt->execute([$summary, $entryId]);
    }

    public function getEntry(int $entryId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM diary_entries WHERE id = ?');
        $stmt->execute([$entryId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Все записи пользователя (обратный хронологический порядок). */
    public function getUserEntries(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM diary_entries WHERE user_id = ? ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countUserEntries(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM diary_entries WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    public function countTodayEntries(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM diary_entries WHERE user_id = ? AND DATE(created_at) = CURDATE()'
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    // ─── diary_messages ───────────────────────────────────────────────────────

    public function saveMessage(int $entryId, string $role, string $content): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO diary_messages (diary_entry_id, role, content) VALUES (?, ?, ?)'
        );
        $stmt->execute([$entryId, $role, $content]);
        return (int)$this->db->lastInsertId();
    }

    public function getMessages(int $entryId): array
    {
        $stmt = $this->db->prepare(
            'SELECT role, content, created_at FROM diary_messages WHERE diary_entry_id = ? ORDER BY id'
        );
        $stmt->execute([$entryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
