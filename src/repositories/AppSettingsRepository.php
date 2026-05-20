<?php

require_once __DIR__ . '/../services/Database/Database.php';

class AppSettingsRepository
{
    private PDO $db;
    private array $cache = [];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $stmt = $this->db->prepare('SELECT value FROM app_settings WHERE key_name = ?');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        $this->cache[$key] = ($val !== false) ? $val : $default;
        return $this->cache[$key];
    }

    public function set(string $key, string $value, string $description = ''): void
    {
        $this->db->prepare(
            'INSERT INTO app_settings (key_name, value, description)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        )->execute([$key, $value, $description]);
        $this->cache[$key] = $value;
    }

    public function getAll(): array
    {
        $stmt = $this->db->query('SELECT key_name, value, description FROM app_settings ORDER BY key_name');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
