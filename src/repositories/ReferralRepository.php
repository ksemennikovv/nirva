<?php

require_once __DIR__ . '/../services/Database/Database.php';

class ReferralRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Возвращает реферальный код пользователя.
     * Если записи ещё нет — создаёт новую с уникальным кодом.
     */
    public function findOrCreateCode(int $userId): string
    {
        $stmt = $this->db->prepare(
            'SELECT referral_code FROM referrals
             WHERE referrer_user_id = ? AND referred_user_id IS NULL
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return $row['referral_code'];
        }

        $code = substr(md5(uniqid((string)$userId, true)), 0, 12);

        $stmt = $this->db->prepare(
            'INSERT INTO referrals (referrer_user_id, referral_code) VALUES (?, ?)'
        );
        $stmt->execute([$userId, $code]);

        return $code;
    }

    /** Количество приведённых пользователей которым выдана награда. */
    public function countRewarded(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM referrals
             WHERE referrer_user_id = ? AND referred_user_id IS NOT NULL AND reward_granted = 1'
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
}
