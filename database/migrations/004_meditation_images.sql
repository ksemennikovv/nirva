-- ── 004_meditation_images.sql ─────────────────────────────────────────────────
-- Добавляет image_url к медитациям и назначает фоны по категориям.
-- ──────────────────────────────────────────────────────────────────────────────

ALTER TABLE meditations ADD COLUMN image_url VARCHAR(500) NULL AFTER demo_audio_url;

UPDATE meditations m
JOIN meditation_categories c ON c.id = m.category_id
SET m.image_url = CONCAT('/assets/images/meditations/', c.slug, '.svg')
WHERE m.type = 'general' AND m.user_id IS NULL;
