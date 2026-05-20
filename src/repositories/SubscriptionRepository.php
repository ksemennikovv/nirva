<?php

require_once __DIR__ . '/../services/Database/Database.php';

class SubscriptionRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO subscriptions
                (user_id, plan, period, analyses_per_month, starts_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['user_id'],
            $data['plan'],
            $data['period'],
            $data['analyses_per_month'],
            $data['starts_at'],
            $data['expires_at'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    /** Активная подписка пользователя (или null). */
    public function getActive(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM subscriptions
             WHERE user_id = ? AND status = "active" AND expires_at > NOW()
             ORDER BY expires_at DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function incrementUsed(int $subscriptionId): void
    {
        $this->db->prepare(
            'UPDATE subscriptions SET analyses_used = analyses_used + 1 WHERE id = ?'
        )->execute([$subscriptionId]);
    }

    public function updateStatus(int $subscriptionId, string $status): void
    {
        $this->db->prepare(
            'UPDATE subscriptions SET status = ? WHERE id = ?'
        )->execute([$status, $subscriptionId]);
    }

    public function getUserSubscriptions(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM subscriptions WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Количество разборов доступных сейчас (remaining = per_month - used). */
    public function getAvailableAnalyses(int $userId): int
    {
        $sub = $this->getActive($userId);
        if (!$sub) return 0;
        return max(0, $sub['analyses_per_month'] - $sub['analyses_used']);
    }
}
