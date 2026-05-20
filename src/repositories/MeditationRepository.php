<?php

require_once __DIR__ . '/../services/Database/Database.php';

class MeditationRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO meditations
                (category_id, user_id, analysis_id, type, topic_type, topic, title,
                 description, personal_context, demo_audio_url, full_audio_url,
                 price, is_free_first_month, generation_status, generation_provider,
                 generation_job_id, expires_at)
             VALUES
                (:category_id, :user_id, :analysis_id, :type, :topic_type, :topic, :title,
                 :description, :personal_context, :demo_audio_url, :full_audio_url,
                 :price, :is_free_first_month, :generation_status, :generation_provider,
                 :generation_job_id, :expires_at)'
        );
        $stmt->execute([
            ':category_id'         => $data['category_id']         ?? null,
            ':user_id'             => $data['user_id']             ?? null,
            ':analysis_id'         => $data['analysis_id']         ?? null,
            ':type'                => $data['type']                ?? 'personal',
            ':topic_type'          => $data['topic_type']          ?? 'user_specific',
            ':topic'               => $data['topic']               ?? null,
            ':title'               => $data['title']               ?? null,
            ':description'         => $data['description']         ?? null,
            ':personal_context'    => $data['personal_context']    ?? null,
            ':demo_audio_url'      => $data['demo_audio_url']      ?? null,
            ':full_audio_url'      => $data['full_audio_url']      ?? null,
            ':price'               => $data['price']               ?? 0,
            ':is_free_first_month' => $data['is_free_first_month'] ?? 0,
            ':generation_status'   => $data['generation_status']   ?? 'pending',
            ':generation_provider' => $data['generation_provider'] ?? null,
            ':generation_job_id'   => $data['generation_job_id']   ?? null,
            ':expires_at'          => $data['expires_at']          ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM meditations WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateStatus(int $id, string $status, array $extra = []): void
    {
        $sets = ['generation_status = ?'];
        $params = [$status];

        if (isset($extra['generation_job_id'])) {
            $sets[] = 'generation_job_id = ?';
            $params[] = $extra['generation_job_id'];
        }
        if (isset($extra['full_audio_url'])) {
            $sets[] = 'full_audio_url = ?';
            $params[] = $extra['full_audio_url'];
        }
        if (isset($extra['demo_audio_url'])) {
            $sets[] = 'demo_audio_url = ?';
            $params[] = $extra['demo_audio_url'];
        }
        if (isset($extra['title'])) {
            $sets[] = 'title = ?';
            $params[] = $extra['title'];
        }
        if (isset($extra['description'])) {
            $sets[] = 'description = ?';
            $params[] = $extra['description'];
        }
        if (isset($extra['personal_context'])) {
            $sets[] = 'personal_context = ?';
            $params[] = $extra['personal_context'];
        }
        if (isset($extra['topic'])) {
            $sets[] = 'topic = ?';
            $params[] = $extra['topic'];
        }

        $params[] = $id;
        $sql = 'UPDATE meditations SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($params);
    }

    /** Готовые персональные медитации пользователя (не истёкшие). */
    public function getUserReadyMeditations(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM meditations
             WHERE user_id = ?
               AND type = "personal"
               AND generation_status = "ready"
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Все персональные медитации пользователя, сгруппированные по разбору.
     * Используется в каталоге медитаций чтобы показать подборки из всех разборов.
     */
    public function getPersonalGroupedByAnalysis(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.id, m.title, m.topic, m.description, m.price,
                    m.demo_audio_url, m.image_url, m.analysis_id,
                    m.generation_status,
                    s.topic AS analysis_topic, s.completed_at,
                    COALESCE(mp.id, 0) AS is_purchased
             FROM meditations m
             LEFT JOIN analysis_sessions s ON s.id = m.analysis_id
             LEFT JOIN meditation_purchases mp ON mp.meditation_id = m.id
             WHERE m.user_id = ?
               AND m.type = "personal"
               AND m.generation_status = "ready"
               AND (m.expires_at IS NULL OR m.expires_at > NOW())
             ORDER BY m.analysis_id DESC, m.id ASC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($rows as $row) {
            $aid = (int)$row['analysis_id'];
            if (!isset($groups[$aid])) {
                $groups[$aid] = [
                    'analysis_id'    => $aid,
                    'analysis_topic' => $row['analysis_topic'] ?? 'Разбор #' . $aid,
                    'completed_at'   => $row['completed_at'],
                    'items'          => [],
                ];
            }
            $groups[$aid]['items'][] = $row;
        }
        return array_values($groups);
    }

    /** Медитации разбора с флагом покупки для конкретного пользователя. */
    public function getByAnalysis(int $analysisId, int $userId = 0): array
    {
        if ($userId) {
            $stmt = $this->db->prepare(
                'SELECT m.id, m.title, m.topic, m.description, m.generation_status,
                        m.demo_audio_url, m.full_audio_url, m.image_url, m.price, m.expires_at,
                        COALESCE(mp.id, 0) AS is_purchased
                 FROM meditations m
                 LEFT JOIN meditation_purchases mp ON mp.meditation_id = m.id AND mp.user_id = ?
                 WHERE m.analysis_id = ? ORDER BY m.id'
            );
            $stmt->execute([$userId, $analysisId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT id, title, topic, description, generation_status,
                        demo_audio_url, full_audio_url, image_url, price, expires_at,
                        0 AS is_purchased
                 FROM meditations WHERE analysis_id = ? ORDER BY id'
            );
            $stmt->execute([$analysisId]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Pending-медитации для фоновой обработки. */
    public function getPending(int $limit = 5): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM meditations WHERE generation_status = "pending" ORDER BY created_at LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Общие медитации со статусом ready. */
    public function getGeneralMeditations(?int $userId = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*,
                    CASE WHEN mp.id IS NOT NULL THEN 1 ELSE 0 END AS is_purchased
             FROM meditations m
             LEFT JOIN meditation_purchases mp ON mp.meditation_id = m.id AND mp.user_id = :uid
             WHERE m.type = "general" AND m.generation_status = "ready"
             ORDER BY m.id'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isPurchased(int $userId, int $meditationId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM meditation_purchases WHERE user_id = ? AND meditation_id = ?'
        );
        $stmt->execute([$userId, $meditationId]);
        return (bool)$stmt->fetchColumn();
    }

    public function addPurchase(int $userId, int $meditationId): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO meditation_purchases (user_id, meditation_id) VALUES (?, ?)'
        );
        $stmt->execute([$userId, $meditationId]);
    }

    /** Общие медитации, сгруппированные по категориям. */
    public function getGeneralByCategory(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*,
                    COALESCE(m.image_url, CONCAT("/assets/images/meditations/", c.slug, ".svg")) AS image_url,
                    c.name  AS category_name,
                    c.slug  AS category_slug,
                    c.sort_order AS category_sort,
                    CASE WHEN mp.id IS NOT NULL THEN 1 ELSE 0 END AS is_purchased
             FROM meditations m
             JOIN meditation_categories c ON c.id = m.category_id
             LEFT JOIN meditation_purchases mp ON mp.meditation_id = m.id AND mp.user_id = :uid
             WHERE m.type = "general"
               AND m.user_id IS NULL
               AND m.generation_status = "ready"
             ORDER BY c.sort_order, m.id'
        );
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $slug = $row['category_slug'];
            if (!isset($grouped[$slug])) {
                $grouped[$slug] = [
                    'name'        => $row['category_name'],
                    'slug'        => $slug,
                    'sort_order'  => $row['category_sort'],
                    'meditations' => [],
                ];
            }
            $grouped[$slug]['meditations'][] = $row;
        }
        return array_values($grouped);
    }
}
