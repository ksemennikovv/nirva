-- schema_v2.sql — Схема базы данных приложения Nirva.
--
-- Порядок создания таблиц важен из-за внешних ключей:
--   1. users
--   2. verification_codes   (ссылается на users.id — nullable)
--   3. analysis_sessions    (ссылается на users.id — nullable, анонимные сессии)
--   4. messages             (ссылается на analysis_sessions.id)
--   5. practice_access      (ссылается на users.id)
--
-- Запуск: mysql -u root -p nirva < database/schema_v2.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── users ────────────────────────────────────────────────────────────────────
-- Зарегистрированные пользователи приложения.
-- Создаётся после подтверждения email через verification_codes.

CREATE TABLE IF NOT EXISTS users (
    id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    email      VARCHAR(255)    NOT NULL,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── verification_codes ───────────────────────────────────────────────────────
-- Коды подтверждения email.
-- Привязаны к email (не к user_id), так как код отправляется до создания пользователя.
-- Поле used_at — NULL означает «ещё не использован».

CREATE TABLE IF NOT EXISTS verification_codes (
    id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    email      VARCHAR(255)    NOT NULL,
    code       VARCHAR(10)     NOT NULL,
    expires_at TIMESTAMP       NOT NULL,
    used_at    TIMESTAMP       NULL DEFAULT NULL,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_verification_codes_email (email),
    KEY idx_verification_codes_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── analysis_sessions ────────────────────────────────────────────────────────
-- Сессия анализа пользователя (диалог с AI).
-- user_id может быть NULL — анонимная сессия до регистрации;
-- после регистрации user_id обновляется.
--
-- status:
--   'active'    — диалог в процессе
--   'completed' — AI подобрал практику (финальный [ANALYSIS_RESULT] получен)
--   'closed'    — принудительно закрыт пользователем
--
-- current_state — текущий hero-state на landing:
--   'default-hero' | 'unfinished-analysis' | 'registration-gate'
--
-- selected_practice — название практики, выбранной AI по итогам анализа.

CREATE TABLE IF NOT EXISTS analysis_sessions (
    id                INT UNSIGNED                                    NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED                                    NULL DEFAULT NULL,
    status            ENUM('active', 'completed', 'closed')           NOT NULL DEFAULT 'active',
    current_state     VARCHAR(50)                            NOT NULL DEFAULT 'default-hero',
    selected_practice VARCHAR(255)                           NULL DEFAULT NULL,
    created_at        TIMESTAMP                              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP                              NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_analysis_sessions_user_id (user_id),
    KEY idx_analysis_sessions_status (status),
    CONSTRAINT fk_analysis_sessions_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── messages ─────────────────────────────────────────────────────────────────
-- Сообщения диалога внутри analysis_session.
--
-- role:
--   'user'      — сообщение пользователя
--   'assistant' — ответ AI

CREATE TABLE IF NOT EXISTS messages (
    id                  INT UNSIGNED            NOT NULL AUTO_INCREMENT,
    analysis_session_id INT UNSIGNED            NOT NULL,
    role                ENUM('user','assistant') NOT NULL,
    content             TEXT                    NOT NULL,
    created_at          TIMESTAMP               NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_messages_session_id (analysis_session_id),
    CONSTRAINT fk_messages_session
        FOREIGN KEY (analysis_session_id) REFERENCES analysis_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── practice_access ──────────────────────────────────────────────────────────
-- Доступ пользователя к практике в личном кабинете.
-- Создаётся после регистрации и завершения analysis_session.

CREATE TABLE IF NOT EXISTS practice_access (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED    NOT NULL,
    practice_id VARCHAR(255)    NOT NULL,
    granted_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_practice_access_user_id (user_id),
    CONSTRAINT fk_practice_access_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
