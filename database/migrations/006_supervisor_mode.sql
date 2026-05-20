-- Migration 006: Режим отладки психолога (Supervisor Mode)

-- Добавить статус проверки в таблицу messages
ALTER TABLE messages
    ADD COLUMN review_status   ENUM('approved','pending_review','rejected') NOT NULL DEFAULT 'approved' AFTER phase,
    ADD COLUMN reviewed_content TEXT NULL AFTER review_status,
    ADD COLUMN reviewed_at      TIMESTAMP NULL AFTER reviewed_content;

-- Хранить распаршенные метаданные ИИ до момента апрува
ALTER TABLE analysis_sessions
    ADD COLUMN pending_metadata JSON NULL;

-- Коррекции психолога
CREATE TABLE IF NOT EXISTS supervisor_corrections (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id      INT UNSIGNED NOT NULL,
    rejected_msg_id INT UNSIGNED NOT NULL,
    instruction     TEXT NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sc_session (session_id),
    CONSTRAINT fk_sc_session FOREIGN KEY (session_id)      REFERENCES analysis_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_sc_msg     FOREIGN KEY (rejected_msg_id) REFERENCES messages(id)          ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
