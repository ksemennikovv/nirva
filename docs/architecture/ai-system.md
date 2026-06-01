# ИИ-система Nirva

## Общая идея

В Nirva есть три режима ИИ-диалога: анализ (разбор снов/переживаний), дневник и генерация медитаций. Все три используют единый сервис `AIService`, который абстрагирует конкретного провайдера (OpenAI, Claude, DeepSeek). Провайдер и модель для каждого режима хранятся в `app_settings` и переключаются через админку.

---

## Файлы ИИ-системы

- `src/services/AI/AIService.php` — главный класс, диспетчер провайдеров
- `config/ai.php` — ключи API и дефолтные параметры (fallback если в БД нет настроек)
- `prompts/` — системные промпты для каждого режима (отдельные файлы)
- `features/chat-roller/api/send-message.php` — точка входа для всех чат-запросов

---

## Как AIService выбирает провайдера

```php
// В getResponse() — смотрит настройку в БД, fallback на константу из config/ai.php
$provider = BusinessConfig::setting('ai_provider_analysis', AI_PROVIDER);
$model    = BusinessConfig::setting('ai_model_analysis',    AI_MODEL);

return match ($provider) {
    'claude'   => $this->getResponseClaude($conversation, $model),
    'deepseek' => $this->getResponseDeepSeek($conversation, $model),
    default    => $this->getResponseOpenAI($conversation, $model),
};
```

Каждый из трёх методов делает CURL-запрос к своему API. DeepSeek использует OpenAI-совместимый формат. Claude требует отдельного поля `system` (не в массиве messages).

---

## Режимы диалога

### analysis — разбор переживания
- Точка входа: `send-message.php` → `sendAnalysisMessage()`
- Системный промпт: файл из `prompts/` для анализа
- ИИ проходит через несколько этапов диалога (dialogue_stage): углубление → выявление паттернов → инсайт → практика
- После каждого ответа: `ProfileService::updateProfile()` — ИИ предлагает обновления профиля
- Резюме диалога сжимается по этапам (dialogue_summary) — чтобы не раздувать контекст

### reflection — рефлексия (подтип analysis)
- Это продолжение анализа, но в другой фазе (practice-работа)
- Используется тот же `ai_provider_analysis` / `ai_model_analysis`
- Промпт другой — фокус на интеграции инсайта

### diary — дневник
- Точка входа: `send-message.php` → `sendDiaryMessage()`
- Использует `ai_provider_diary` / `ai_model_diary`
- Более лёгкий диалог: поддержка, наблюдение, без глубокого анализа

---

## Проверка безопасности (checkSafety)

Перед каждым ответом ИИ вызывается `AIService::checkSafety($userMessage)`:
- Отдельный быстрый запрос к ИИ с промптом "оцени кризисность"
- Возвращает: `{safe: bool, severity: none/mild/moderate/severe, risk_level: ...}`
- Если `safe = false` — сообщение помечается высоким risk_level
- При `risk_level = high` администратор видит уведомление в очереди модерации

---

## Supervisor mode — модерация ответов

Когда `supervisor_mode = true` (в app_settings):

1. ИИ генерирует ответ → сохраняется в `messages.review_status = 'pending_review'`
2. Пользователь видит "думаю..." (фронтенд polling-ом ждёт approved)
3. В `/admin/` появляется очередь: администратор видит текст, может отредактировать и одобрить
4. После одобрения `review_status = 'approved'` — пользователь видит ответ

Метод `MessageRepository::getApprovedMessages()` возвращает только одобренные сообщения для контекста следующего запроса к ИИ.

---

## Генерация текста медитации

Отдельный поток (не через send-message.php):
- `MeditationService::generateTextFromAI()` формирует специальный промпт
- Контекст: последний анализ + психологический профиль пользователя (`ProfileService::formatForPrompt()`)
- ИИ возвращает JSON: `{title, description, context, topic, audio_text}`
- `audio_text` — полный текст для озвучки (2-5 минут)

---

## Аудио-генерация (ElevenLabs)

`src/services/ElevenLabs/ElevenLabsService.php`:
- Принимает `audio_text` и `meditationId`
- POST в ElevenLabs через Cloudflare Worker proxy (для обхода гео-ограничений)
- Voice ID захардкожен в `config/ai.php` (голос `9BWtsMINqrJLrRacOk9x`)
- Сохраняет MP3 как `assets/audio/meditations/{id}.mp3`
- Таймаут 120 секунд (длинные тексты)

---

## Генерация изображений

`src/services/ImageGeneration/ImageGenerationService.php` — диспетчер:
- Выбирает `FluxImageService` (Fal.ai) или `GeminiImageService` (NanoBanana) по настройке `image_provider`
- Строит промпт по шаблону с подстановкой `{title}`, `{description}`, `{topic}`, `{style}`
- Для Flux: синхронный запрос, сразу получает URL
- Для Gemini: асинхронный, поллинг до 40 раз с паузой 3 сек
- Результат скачивается и сохраняется локально в `assets/images/meditations/`

История версий изображений: `src/services/ImageGeneration/ImageHistoryService.php` — хранит предыдущие варианты, позволяет откатиться.

---

## Параметры ИИ (дефолты из config/ai.php)

| Параметр | Значение |
|----------|---------|
| temperature | 0.70 — умеренное разнообразие ответов |
| max_tokens | 350 — лимит длины ответа (для диалога достаточно) |
| Модель по умолчанию | gpt-4o |

При генерации текста медитации max_tokens выше (текст длиннее диалогового ответа).
