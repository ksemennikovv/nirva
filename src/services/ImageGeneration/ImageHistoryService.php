<?php

/**
 * Управление историей картинок медитаций.
 * Перед заменой картинки вызвать archiveCurrent(),
 * после сохранения новой — recordNew().
 */
class ImageHistoryService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Сохраняет текущую image_url медитации в историю (если она есть).
     * Вызывать ДО записи новой картинки.
     *
     * @param string $source   Источник новой картинки ('generated','uploaded','url') — помечается на архивируемой записи
     * @param string $provider Провайдер новой картинки (flux/imagen/etc)
     */
    public function archiveCurrent(int $meditationId, string $source = 'generated', string $provider = ''): void
    {
        $stmt = $this->db->prepare("SELECT image_url, generation_provider FROM meditations WHERE id = ?");
        $stmt->execute([$meditationId]);
        $row     = $stmt->fetch(PDO::FETCH_ASSOC);
        $current = $row['image_url'] ?? null;
        // Берём провайдер из поля generation_provider если не передан явно
        if (!$provider && !empty($row['generation_provider'])) {
            $provider = $row['generation_provider'];
        }

        if ($current) {
            // Проверяем что такой URL ещё не последняя запись в истории (защита от дублей)
            $lastStmt = $this->db->prepare(
                "SELECT image_url FROM meditation_image_history WHERE meditation_id = ? ORDER BY id DESC LIMIT 1"
            );
            $lastStmt->execute([$meditationId]);
            $lastUrl = $lastStmt->fetchColumn();

            if ($lastUrl !== $current) {
                $this->db->prepare(
                    "INSERT INTO meditation_image_history (meditation_id, image_url, source, provider) VALUES (?, ?, ?, ?)"
                )->execute([$meditationId, $current, $source, $provider ?: null]);
            }
        }
    }

    /**
     * Возвращает историю картинок медитации (новые сверху).
     */
    public function getHistory(int $meditationId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM meditation_image_history
             WHERE meditation_id = ?
             ORDER BY id DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $meditationId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,        PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Откатывает картинку к записи из истории.
     */
    public function rollbackTo(int $meditationId, int $historyId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT image_url FROM meditation_image_history WHERE id = ? AND meditation_id = ?"
        );
        $stmt->execute([$historyId, $meditationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        // Архивируем текущую перед откатом
        $this->archiveCurrent($meditationId);

        // Ставим историческую
        $this->db->prepare("UPDATE meditations SET image_url = ? WHERE id = ?")
                 ->execute([$row['image_url'], $meditationId]);

        return true;
    }

    /**
     * Удаляет запись из истории.
     */
    public function deleteRecord(int $historyId, int $meditationId): void
    {
        // Получаем URL чтобы удалить файл если он локальный
        $stmt = $this->db->prepare(
            "SELECT image_url FROM meditation_image_history WHERE id = ? AND meditation_id = ?"
        );
        $stmt->execute([$historyId, $meditationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->deleteLocalFile($row['image_url']);
            $this->db->prepare("DELETE FROM meditation_image_history WHERE id = ?")->execute([$historyId]);
        }
    }

    /**
     * Удаляет локальный файл если путь начинается с /assets/
     */
    public function deleteLocalFile(string $url): void
    {
        if (!str_starts_with($url, '/assets/')) return;
        $path = dirname(__DIR__, 3) . $url;
        if (file_exists($path)) @unlink($path);
    }
}
