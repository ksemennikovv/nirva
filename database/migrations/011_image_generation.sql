-- Колонка image_url (migration 004 могла добавить — используем IF NOT EXISTS через процедуру)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meditations'
      AND COLUMN_NAME = 'image_url'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE meditations ADD COLUMN image_url VARCHAR(500) NULL AFTER full_audio_url',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Настройки провайдеров и промтов
INSERT IGNORE INTO app_settings (key_name, value, description) VALUES
  ('image_provider_personal', 'none',   'Провайдер картинок для персональных медитаций (none / flux / imagen)'),
  ('image_provider_general',  'none',   'Провайдер картинок для общих медитаций (none / flux / imagen)'),
  ('image_prompt_personal',
   'sacred feminine energy, {title}, spiritual healing meditation, {description}, soft divine golden light, ethereal atmosphere, dreamlike sacred space, woman silhouette, no text, no watermark, vertical portrait, cinematic quality, {style}',
   'Шаблон промта для персональных медитаций. Переменные: {title} {description} {topic} {category} {style}'),
  ('image_prompt_general',
   'meditation artwork, {title}, {category} theme, {description}, soft pastel light, peaceful sacred space, spiritual atmosphere, abstract divine, no text, no watermark, square format, high quality, {style}',
   'Шаблон промта для общих медитаций. Переменные: {title} {description} {topic} {category} {style}'),
  ('image_style_flux',   'photorealistic, sharp details, 8K resolution, Flux aesthetic',  'Стилевой суффикс для Flux Pro (добавляется к промту)'),
  ('image_style_imagen', 'painterly, vivid colors, Google Imagen style, artistic',         'Стилевой суффикс для Imagen 3 / NanoBanana (добавляется к промту)');
