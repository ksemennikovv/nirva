-- migration 001_v3_schema.sql
-- Расширение схемы Nirva до v3: auth, профиль, дневник, медитации, подписки, платежи, рефералы.
--
-- Применять ПОСЛЕ schema_v2.sql (таблицы users, verification_codes,
-- analysis_sessions, messages, practice_access уже существуют).
--
-- Запуск: mysql -u root -p nirva < database/migrations/001_v3_schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── users: пароль ────────────────────────────────────────────────────────────

ALTER TABLE users
    ADD COLUMN password_hash VARCHAR(255) NULL DEFAULT NULL AFTER email;

-- ─── analysis_sessions: расширение статусов ──────────────────────────────────

ALTER TABLE analysis_sessions
    MODIFY COLUMN status ENUM(
        'active',
        'draft_started',
        'chat_in_progress',
        'analysis_completed',
        'practice_assigned',
        'practice_completed',
        'reflection_in_progress',
        'completed',
        'abandoned',
        'closed'
    ) NOT NULL DEFAULT 'active';

ALTER TABLE analysis_sessions ADD COLUMN topic                VARCHAR(255) NULL DEFAULT NULL AFTER selected_practice;
ALTER TABLE analysis_sessions ADD COLUMN analysis_summary     TEXT         NULL DEFAULT NULL AFTER topic;
ALTER TABLE analysis_sessions ADD COLUMN personal_task        TEXT         NULL DEFAULT NULL AFTER analysis_summary;
ALTER TABLE analysis_sessions ADD COLUMN reflection_summary   TEXT         NULL DEFAULT NULL AFTER personal_task;
ALTER TABLE analysis_sessions ADD COLUMN final_recommendations TEXT        NULL DEFAULT NULL AFTER reflection_summary;
ALTER TABLE analysis_sessions ADD COLUMN completed_at         TIMESTAMP    NULL DEFAULT NULL AFTER final_recommendations;

-- ─── profile_parameters ───────────────────────────────────────────────────────
-- Справочник параметров психоэмоционального портрета.
-- value_type: 'fixed' — только из predefined_options; 'individual' — уникальные строки юзера.
-- category: группировка для промптов и аналитики.

CREATE TABLE IF NOT EXISTS profile_parameters (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(100) NOT NULL,
    label       VARCHAR(255) NOT NULL,
    description TEXT         NULL,
    value_type  ENUM('fixed','individual') NOT NULL DEFAULT 'individual',
    category    ENUM('defense','fear','body','attachment','emotion','behavior','relationship','trauma','identity') NOT NULL,
    sort_order  SMALLINT     NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    UNIQUE KEY uq_profile_parameters_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── profile_parameter_options ────────────────────────────────────────────────
-- Фиксированные варианты значений для параметров с value_type='fixed'.

CREATE TABLE IF NOT EXISTS profile_parameter_options (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parameter_id INT UNSIGNED NOT NULL,
    option_value VARCHAR(255) NOT NULL,
    sort_order   SMALLINT     NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    KEY idx_ppo_parameter_id (parameter_id),
    CONSTRAINT fk_ppo_parameter
        FOREIGN KEY (parameter_id) REFERENCES profile_parameters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── profile_parameter_values ─────────────────────────────────────────────────
-- Текущие значения параметров портрета пользователя.
-- value — JSON массив объектов:
--   {"value": "избегание", "confidence": 0.81, "evidence_count": 7, "updated_at": "2026-05-18T..."}

CREATE TABLE IF NOT EXISTS profile_parameter_values (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    parameter_id INT UNSIGNED NOT NULL,
    value        JSON         NOT NULL,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_ppv_user_param (user_id, parameter_id),
    KEY idx_ppv_user_id (user_id),
    CONSTRAINT fk_ppv_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ppv_parameter
        FOREIGN KEY (parameter_id) REFERENCES profile_parameters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── profile_parameter_history ────────────────────────────────────────────────
-- Event-based история изменений параметров портрета.
-- event_type: 'added' | 'removed' | 'updated'
-- event_data примеры:
--   added:   {"value": "избегание", "confidence": 0.81}
--   removed: {"value": "избегание"}
--   updated: {"value": "избегание", "old_confidence": 0.60, "new_confidence": 0.81, "new_evidence_count": 7}

CREATE TABLE IF NOT EXISTS profile_parameter_history (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    parameter_id INT UNSIGNED NOT NULL,
    event_type   ENUM('added','removed','updated') NOT NULL,
    event_data   JSON         NOT NULL,
    source_type  ENUM('analysis','reflection','diary') NOT NULL,
    source_id    INT UNSIGNED NOT NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_pph_user_id (user_id),
    KEY idx_pph_parameter_id (parameter_id),
    KEY idx_pph_created_at (created_at),
    CONSTRAINT fk_pph_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_pph_parameter
        FOREIGN KEY (parameter_id) REFERENCES profile_parameters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── user_memories ────────────────────────────────────────────────────────────
-- Свободные AI-наблюдения, дополняющие структурированный профиль.
-- importance_score (1-10) используется для retrieval ranking в промптах.

CREATE TABLE IF NOT EXISTS user_memories (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          INT UNSIGNED NOT NULL,
    content          TEXT         NOT NULL,
    importance_score TINYINT UNSIGNED NOT NULL DEFAULT 5,
    source_type      ENUM('analysis','reflection','diary') NULL,
    source_id        INT UNSIGNED NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_um_user_id (user_id),
    KEY idx_um_importance (user_id, importance_score),
    CONSTRAINT fk_um_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── diary_entries ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS diary_entries (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    summary    TEXT         NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_diary_entries_user_id (user_id),
    CONSTRAINT fk_diary_entries_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── diary_messages ───────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS diary_messages (
    id             INT UNSIGNED             NOT NULL AUTO_INCREMENT,
    diary_entry_id INT UNSIGNED             NOT NULL,
    role           ENUM('user','assistant') NOT NULL,
    content        TEXT                     NOT NULL,
    created_at     TIMESTAMP                NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_diary_messages_entry_id (diary_entry_id),
    CONSTRAINT fk_diary_messages_entry
        FOREIGN KEY (diary_entry_id) REFERENCES diary_entries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── meditation_categories ────────────────────────────────────────────────────
-- user_id NULL = общая категория; NOT NULL = персональная категория пользователя.

CREATE TABLE IF NOT EXISTS meditation_categories (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL DEFAULT NULL,
    name        VARCHAR(255) NOT NULL,
    slug        VARCHAR(100) NULL,
    description TEXT         NULL,
    sort_order  SMALLINT     NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_mc_user_id (user_id),
    CONSTRAINT fk_mc_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── meditations ──────────────────────────────────────────────────────────────
-- type: 'general' — общая (~100 штук); 'personal' — сгенерированная под юзера.
-- topic_type: 'general' — тема общая; 'user_specific' — тема из профиля юзера.
-- is_free_first_month: администратор помечает медитации, доступные бесплатно в 1-й месяц (7 штук).
-- expires_at: NULL = бессрочно; NOT NULL = истекает по правилу из app_settings.
-- generation_status: для персональных (type='personal'); у general всегда 'ready'.

CREATE TABLE IF NOT EXISTS meditations (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id         INT UNSIGNED NULL DEFAULT NULL,
    user_id             INT UNSIGNED NULL DEFAULT NULL,
    analysis_id         INT UNSIGNED NULL DEFAULT NULL,
    type                ENUM('general','personal') NOT NULL DEFAULT 'general',
    topic_type          ENUM('general','user_specific') NOT NULL DEFAULT 'general',
    topic               VARCHAR(255) NULL,
    title               VARCHAR(255) NULL,
    description         TEXT         NULL,
    personal_context    TEXT         NULL,
    demo_audio_url      VARCHAR(500) NULL,
    full_audio_url      VARCHAR(500) NULL,
    price               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_free_first_month TINYINT(1)   NOT NULL DEFAULT 0,
    generation_status   ENUM('pending','generating','ready','failed') NOT NULL DEFAULT 'pending',
    generation_provider VARCHAR(100) NULL,
    generation_job_id   VARCHAR(255) NULL,
    expires_at          TIMESTAMP    NULL DEFAULT NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_meditations_user_id (user_id),
    KEY idx_meditations_analysis_id (analysis_id),
    KEY idx_meditations_type_status (type, generation_status),
    KEY idx_meditations_expires_at (expires_at),
    CONSTRAINT fk_meditations_category
        FOREIGN KEY (category_id) REFERENCES meditation_categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_meditations_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_meditations_analysis
        FOREIGN KEY (analysis_id) REFERENCES analysis_sessions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── meditation_purchases ─────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS meditation_purchases (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    meditation_id INT UNSIGNED NOT NULL,
    purchased_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_mp_user_meditation (user_id, meditation_id),
    KEY idx_mp_user_id (user_id),
    CONSTRAINT fk_mp_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_mp_meditation
        FOREIGN KEY (meditation_id) REFERENCES meditations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── subscriptions ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS subscriptions (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             INT UNSIGNED NOT NULL,
    plan                ENUM('start','basic','transformation') NOT NULL,
    period              ENUM('monthly','6months','12months')   NOT NULL,
    analyses_per_month  TINYINT UNSIGNED NOT NULL,
    analyses_used       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    starts_at           TIMESTAMP    NOT NULL,
    expires_at          TIMESTAMP    NOT NULL,
    auto_renew          TINYINT(1)   NOT NULL DEFAULT 1,
    status              ENUM('active','cancelled','expired')   NOT NULL DEFAULT 'active',
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_subscriptions_user_id (user_id),
    KEY idx_subscriptions_status (status),
    KEY idx_subscriptions_expires_at (expires_at),
    CONSTRAINT fk_subscriptions_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── payments ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS payments (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             INT UNSIGNED NOT NULL,
    amount              DECIMAL(10,2) NOT NULL,
    currency            VARCHAR(10)  NOT NULL DEFAULT 'RUB',
    status              ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
    payment_provider    ENUM('yookassa','stripe') NOT NULL,
    provider_payment_id VARCHAR(255) NULL,
    description         TEXT         NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_payments_user_id (user_id),
    KEY idx_payments_status (status),
    KEY idx_payments_provider_id (payment_provider, provider_payment_id),
    CONSTRAINT fk_payments_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── referrals ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS referrals (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    referrer_user_id  INT UNSIGNED NOT NULL,
    referred_user_id  INT UNSIGNED NULL DEFAULT NULL,
    referral_code     VARCHAR(50)  NOT NULL,
    reward_granted    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_referrals_code (referral_code),
    KEY idx_referrals_referrer (referrer_user_id),
    KEY idx_referrals_referred (referred_user_id),
    CONSTRAINT fk_referrals_referrer
        FOREIGN KEY (referrer_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_referrals_referred
        FOREIGN KEY (referred_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── app_settings ─────────────────────────────────────────────────────────────
-- Глобальные настройки приложения (key-value).

CREATE TABLE IF NOT EXISTS app_settings (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    key_name    VARCHAR(100) NOT NULL,
    value       TEXT         NULL,
    description TEXT         NULL,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_app_settings_key (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── background_jobs ──────────────────────────────────────────────────────────
-- Очередь фоновых задач (генерация медитаций, обновление профиля и т.д.).
-- Обрабатывается cron-скриптом или вызовом по триггеру.

CREATE TABLE IF NOT EXISTS background_jobs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type         VARCHAR(100) NOT NULL,
    payload      JSON         NOT NULL,
    status       ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error   TEXT         NULL,
    scheduled_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at   TIMESTAMP    NULL DEFAULT NULL,
    finished_at  TIMESTAMP    NULL DEFAULT NULL,

    PRIMARY KEY (id),
    KEY idx_bj_status_scheduled (status, scheduled_at),
    KEY idx_bj_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Начальные данные: app_settings ──────────────────────────────────────────

INSERT IGNORE INTO app_settings (key_name, value, description) VALUES
    ('meditation_expiry_rule',        'days',  'Правило срока доступности персональных медитаций: days | next_analysis | diary_count'),
    ('meditation_expiry_days',        '30',    'Количество дней доступности (если meditation_expiry_rule=days)'),
    ('meditation_expiry_diary_count', '5',     'Количество записей дневника до истечения (если meditation_expiry_rule=diary_count)'),
    ('free_meditations_first_month',  '7',     'Сколько общих медитаций доступно бесплатно в первый месяц'),
    ('analyses_cooldown_days',        '7',     'Минимальный интервал между разборами в днях');

-- ─── Начальные данные: profile_parameters ────────────────────────────────────

INSERT IGNORE INTO profile_parameters (code, label, value_type, category, sort_order) VALUES
    -- defense
    ('psychological_defenses',   'Психологические защиты',        'fixed',      'defense',      1),
    ('coping_mechanisms',        'Механизмы совладания',           'fixed',      'defense',      2),
    -- fear
    ('phobias',                  'Фобии и страхи',                 'individual', 'fear',         3),
    ('stress_triggers',          'Триггеры стресса',               'individual', 'fear',         4),
    -- body
    ('body_tensions',            'Зажимы в теле',                  'individual', 'body',         5),
    ('body_reactions',           'Телесные реакции на стресс',     'individual', 'body',         6),
    -- attachment
    ('attachment_style',         'Стиль привязанности',            'fixed',      'attachment',   7),
    ('relationship_patterns',    'Паттерны в отношениях',          'fixed',      'relationship', 8),
    -- emotion
    ('dominant_emotions',        'Доминирующие эмоции',            'individual', 'emotion',      9),
    ('emotional_patterns',       'Эмоциональные паттерны',         'individual', 'emotion',      10),
    -- behavior
    ('avoidance_patterns',       'Паттерны избегания',             'individual', 'behavior',     11),
    ('recurring_behaviors',      'Повторяющееся поведение',        'individual', 'behavior',     12),
    -- trauma
    ('core_themes',              'Ключевые темы',                  'individual', 'trauma',       13),
    ('important_events',         'Значимые события',               'individual', 'trauma',       14),
    -- identity
    ('joy_sources',              'Источники радости',              'individual', 'identity',     15),
    ('important_people',         'Значимые люди',                  'individual', 'identity',     16),
    ('personal_symbols',         'Личные символы и образы',        'individual', 'identity',     17),
    -- relationship
    ('recommended_next_topics',  'Рекомендованные темы разборов',  'individual', 'relationship', 18);

-- ─── Начальные данные: profile_parameter_options (fixed-параметры) ────────────

-- psychological_defenses
INSERT IGNORE INTO profile_parameter_options (parameter_id, option_value, sort_order)
SELECT pp.id, v.val, v.srt
FROM profile_parameters pp
CROSS JOIN (
    SELECT 'избегание' AS val, 1 AS srt UNION ALL
    SELECT 'агрессия', 2 UNION ALL
    SELECT 'молчание', 3 UNION ALL
    SELECT 'рационализация', 4 UNION ALL
    SELECT 'отрицание', 5 UNION ALL
    SELECT 'проекция', 6 UNION ALL
    SELECT 'вытеснение', 7 UNION ALL
    SELECT 'интеллектуализация', 8 UNION ALL
    SELECT 'регрессия', 9 UNION ALL
    SELECT 'сублимация', 10
) v
WHERE pp.code = 'psychological_defenses';

-- coping_mechanisms
INSERT IGNORE INTO profile_parameter_options (parameter_id, option_value, sort_order)
SELECT pp.id, v.val, v.srt
FROM profile_parameters pp
CROSS JOIN (
    SELECT 'уход в работу' AS val, 1 AS srt UNION ALL
    SELECT 'изоляция', 2 UNION ALL
    SELECT 'самообвинение', 3 UNION ALL
    SELECT 'поиск поддержки', 4 UNION ALL
    SELECT 'юмор', 5 UNION ALL
    SELECT 'переключение', 6 UNION ALL
    SELECT 'контроль', 7 UNION ALL
    SELECT 'зависимое поведение', 8
) v
WHERE pp.code = 'coping_mechanisms';

-- attachment_style
INSERT IGNORE INTO profile_parameter_options (parameter_id, option_value, sort_order)
SELECT pp.id, v.val, v.srt
FROM profile_parameters pp
CROSS JOIN (
    SELECT 'надёжный' AS val, 1 AS srt UNION ALL
    SELECT 'тревожный', 2 UNION ALL
    SELECT 'избегающий', 3 UNION ALL
    SELECT 'дезорганизованный', 4
) v
WHERE pp.code = 'attachment_style';

-- relationship_patterns
INSERT IGNORE INTO profile_parameter_options (parameter_id, option_value, sort_order)
SELECT pp.id, v.val, v.srt
FROM profile_parameters pp
CROSS JOIN (
    SELECT 'спасатель' AS val, 1 AS srt UNION ALL
    SELECT 'жертва', 2 UNION ALL
    SELECT 'преследователь', 3 UNION ALL
    SELECT 'дистанцирование', 4 UNION ALL
    SELECT 'слияние', 5 UNION ALL
    SELECT 'созависимость', 6
) v
WHERE pp.code = 'relationship_patterns';

SET FOREIGN_KEY_CHECKS = 1;
