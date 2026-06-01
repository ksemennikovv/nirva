-- 007_meditation_listens.sql
-- История прослушиваний медитаций

CREATE TABLE IF NOT EXISTS meditation_listens (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    meditation_id INT UNSIGNED NOT NULL,
    listened_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    duration_sec  INT DEFAULT 0,
    completed     TINYINT(1) DEFAULT 0,
    FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE CASCADE,
    FOREIGN KEY (meditation_id) REFERENCES meditations(id) ON DELETE CASCADE,
    INDEX idx_user_date (user_id, listened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
