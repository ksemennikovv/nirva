<?php

require_once __DIR__ . '/../services/Database/Database.php';

class PaymentRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO payments
                (user_id, amount, currency, status, payment_provider, provider_payment_id, description)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['user_id'],
            $data['amount'],
            $data['currency']             ?? 'RUB',
            $data['status']               ?? 'pending',
            $data['payment_provider'],
            $data['provider_payment_id']  ?? null,
            $data['description']          ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateStatus(int $id, string $status, ?string $providerPaymentId = null): void
    {
        if ($providerPaymentId !== null) {
            $this->db->prepare(
                'UPDATE payments SET status = ?, provider_payment_id = ? WHERE id = ?'
            )->execute([$status, $providerPaymentId, $id]);
        } else {
            $this->db->prepare(
                'UPDATE payments SET status = ? WHERE id = ?'
            )->execute([$status, $id]);
        }
    }

    public function findByProviderPaymentId(string $provider, string $providerPaymentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM payments WHERE payment_provider = ? AND provider_payment_id = ? LIMIT 1'
        );
        $stmt->execute([$provider, $providerPaymentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getUserPayments(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
