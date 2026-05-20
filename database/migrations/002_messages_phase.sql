-- migration 002_messages_phase.sql
-- Добавляет колонку phase в таблицу messages для разделения потоков:
--   'analysis'   — основной разбор
--   'reflection' — самоисследование после практики
--
-- Все существующие записи получают phase='analysis' (default).
--
-- Запуск: mysql -u root -p nirva < database/migrations/002_messages_phase.sql

SET NAMES utf8mb4;

ALTER TABLE messages
    ADD COLUMN phase ENUM('analysis', 'reflection') NOT NULL DEFAULT 'analysis'
        AFTER content;

ALTER TABLE messages
    ADD INDEX idx_messages_session_phase (analysis_session_id, phase);
