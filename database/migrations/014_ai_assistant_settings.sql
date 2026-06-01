-- Миграция 014: Настройки AI-ассистента (провайдер и модель)
-- Единые настройки для всех AI-промтов: разборы, самоисследования, дневник, тексты медитаций.

INSERT IGNORE INTO app_settings (key_name, value, description) VALUES
('ai_provider', 'openai',  'AI провайдер: openai | claude | deepseek'),
('ai_model',    'gpt-4o',  'AI модель (зависит от провайдера)');
