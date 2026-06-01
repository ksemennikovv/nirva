-- migration 008_dialogue_state.sql
-- Adds dialogue state machine fields to analysis_sessions.
-- Run: mysql -u root -p nirva < database/migrations/008_dialogue_state.sql

SET NAMES utf8mb4;

ALTER TABLE analysis_sessions
    ADD COLUMN dialogue_stage ENUM(
        'point_a',
        'point_b',
        'resistance',
        'past_link',
        'body',
        'hypothesis',
        'practice'
    ) NOT NULL DEFAULT 'point_a' AFTER personal_task,

    ADD COLUMN stage_messages_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER dialogue_stage,

    ADD COLUMN dialogue_summary TEXT NULL DEFAULT NULL AFTER stage_messages_count,

    ADD COLUMN risk_level ENUM('safe','elevated','crisis','psychosis') NOT NULL DEFAULT 'safe' AFTER dialogue_summary,

    ADD COLUMN total_messages_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER risk_level;
