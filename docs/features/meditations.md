# Фича: Медитации (/meditations/)

## Что это и зачем

После каждого анализа система автоматически генерирует персональную медитацию — специально под тему и психологический профиль пользователя. Медитация состоит из текста, аудио (озвученного ElevenLabs) и изображения (сгенерированного Flux/Gemini). Пользователь также может слушать общую библиотеку медитаций по категориям.

---

## Ключевые файлы

| Файл | Роль |
|------|------|
| `pages/meditations/index.php` | Список медитаций пользователя |
| `src/services/Meditation/MeditationService.php` | Полный цикл генерации |
| `src/repositories/MeditationRepository.php` | CRUD для медитаций |
| `src/services/ElevenLabs/ElevenLabsService.php` | Текст → MP3 |
| `src/services/ImageGeneration/ImageGenerationService.php` | Диспетчер генерации изображений |
| `src/services/ImageGeneration/FluxImageService.php` | Fal.ai Flux |
| `src/services/ImageGeneration/GeminiImageService.php` | NanoBanana |
| `src/services/ImageGeneration/ImageHistoryService.php` | Версии изображений |
| `features/med-player/` | UI плеера |
| `features/med-card/` | Карточка медитации |
| `src/scripts/process-meditations.php` | Фоновый воркер генерации |

---

## Жизненный цикл медитации

### Шаг 1: Запуск генерации
Вызывается из `AnalysisRepository::completeSession()` → `MeditationService::scheduleGeneration()`:
- Создаёт N записей в `meditations` со статусом `pending` (N = `MEDITATION_GENERATE_COUNT` из настроек)
- Устанавливает `expires_at` = текущая дата + `MEDITATION_FREE_WINDOW_SECONDS` (30 дней)
- Запускает `process-meditations.php` как background subprocess

### Шаг 2: Генерация текста
`MeditationService::generateTextFromAI()`:
- Собирает контекст: последний анализ + психологический профиль (`ProfileService::formatForPrompt()`)
- Отправляет в ИИ специальный промпт
- ИИ возвращает JSON с полями: `title`, `description`, `context`, `topic`, `audio_text`
- `audio_text` — полный текст медитации для озвучки (2-5 минут, ~1000-2000 слов)

### Шаг 3: Аудио
`ElevenLabsService::generateSpeech()`:
- POST к ElevenLabs через Cloudflare Worker proxy (`CLOUDFLARE_ELEVENLABS_PROXY`)
- Голос: `9BWtsMINqrJLrRacOk9x` (настраивается в `config/ai.php`)
- Модель: `eleven_multilingual_v2` — поддерживает русский язык
- Таймаут 120 сек, файлы большие
- Результат: `assets/audio/meditations/{meditationId}.mp3`

### Шаг 4: Изображение
`ImageGenerationService::generate()`:
- Провайдер определяется настройкой `image_provider` в `app_settings` (flux / gemini / none)
- Промпт строится из шаблона с подстановкой темы и профиля
- **Flux (Fal.ai)**: синхронный запрос → сразу получает изображение
- **Gemini (NanoBanana)**: асинхронный → поллинг статуса до 40 раз × 3 сек
- Файл скачивается и сохраняется: `assets/images/meditations/{id}_{timestamp}.jpg`
- Старое изображение архивируется в `ImageHistoryService` (можно откатить)

### Шаг 5: Ready
После успешной генерации всех компонентов:
- `meditations.status = 'ready'`
- Медитация появляется в списке на `/meditations/`

---

## Типы медитаций

### Персональные (source_type = 'personal')
- Привязаны к конкретному `analysis_session_id`
- Создаются автоматически после анализа
- Контент уникален — написан под тему анализа и профиль пользователя
- Доступны бесплатно в течение `expires_at`
- После истечения — платный доступ

### Общие (source_type = 'general')
- Не привязаны к пользователю
- Сгруппированы по категориям
- Часть доступна бесплатно, часть — по подписке

---

## Доступ к медитациям

Логика в `MeditationRepository::isPurchased()` и `expires_at`:
- Если `expires_at` в будущем → бесплатно (30 дней после создания)
- Если истекло → нужна покупка (`meditation_purchases`) или подписка
- Скидка на покупку сета: `MEDITATION_SET_DISCOUNT` = 15%

---

## Воспроизведение

`features/med-player/` — HTML5 audio player:
- Поддерживает play/pause, перемотку, отслеживание прогресса
- При завершении прослушивания → POST в API → `MeditationRepository::recordListen()`
- Статистика прослушиваний за 30 дней: `getListeningStats()`

---

## Регенерация изображения

Администратор может запустить регенерацию изображения для любой медитации из админки:
- Старое изображение архивируется: `ImageHistoryService::archiveCurrent()`
- Новое изображение генерируется с тем же промптом
- Можно откатить к любой предыдущей версии: `ImageHistoryService::rollbackTo()`

---

## Настройки генерации (app_settings)

| Ключ | Значение | Описание |
|------|---------|---------|
| `meditation_auto_generate` | yes/no | Автоматически генерировать после анализа |
| `MEDITATION_GENERATE_COUNT` | 1 | Сколько медитаций создавать за раз |
| `image_provider` | flux/gemini/none | Провайдер изображений |
| `image_model` | flux-pro-1.1/... | Конкретная модель |
| `image_style` | photorealistic/painterly | Стиль изображения |
