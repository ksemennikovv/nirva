# Архитектура Nirva

## Что за проект и зачем он нужен

Nirva — платформа для психологической работы через ИИ-диалог. Пользователь рассказывает сон или переживание, ИИ задаёт уточняющие вопросы, формирует анализ и психологический профиль. На основе анализа генерируется персональная медитация (текст → аудио → изображение). Между сессиями анализа пользователь ведёт дневник.

---

## Стек

| Уровень | Технология |
|---------|-----------|
| Backend | PHP (без фреймворка), PDO + MySQL |
| Frontend | Vanilla JS, CSS Custom Properties, без бандлера |
| ИИ-диалог | OpenAI GPT-4o / Claude / DeepSeek (переключаемые через БД) |
| Аудио | ElevenLabs TTS через Cloudflare Worker proxy |
| Изображения | Fal.ai Flux, NanoBanana (Gemini Imagen) |
| Платежи | YooKassa (Россия), Stripe (остальной мир) |
| Сервер | Apache + .htaccess |

---

## Bootstrap — что происходит при каждом запросе

1. Apache получает запрос, `.htaccess` переписывает URL на нужный PHP-файл (или оставляет как есть для API)
2. `index.php` — точка входа:
   - `session_start()` — стартует PHP-сессию
   - `require config/app.php` — устанавливает режим (dev/prod), обработку ошибок, логирование
   - `require config/database.php` — подключает константы БД
   - `require config/business.php` — загружает BusinessConfig с кешированием настроек из БД
   - `require config/ai.php` — ключи API, параметры ИИ
   - `require config/services.php` — подключает все service/repository классы через autoload
3. Управление переходит на страницу (например, `pages/dashboard/index.php`)
4. Страница включает нужные middleware (`require src/middleware/auth.php`) — они прерывают выполнение при ошибке авторизации
5. Страница работает с репозиториями для получения данных
6. HTML рендерится прямо в PHP-файле страницы

---

## Паттерны, которые используются везде

### Repository pattern
Весь SQL — только в `src/repositories/`. Страницы и сервисы не пишут запросы напрямую. Каждый репозиторий отвечает за одну таблицу/сущность.

### Singleton Database
`src/services/Database/Database.php` держит одно PDO-соединение на весь запрос. Все репозитории вызывают `Database::getConnection()`.

### BusinessConfig — настройки из БД
`config/business.php` содержит класс `BusinessConfig`. Динамические настройки (флаги, ключи провайдеров, лимиты) хранятся в таблице `app_settings` и читаются методом `BusinessConfig::setting($key, $default)`. Константы в PHP-файлах — лишь дефолтные значения.

### Middleware через include
Страницы подключают middleware как PHP-файлы в начале:
```php
require_once $_SERVER['DOCUMENT_ROOT'] . '/src/middleware/auth.php';
// $currentUserId теперь доступна
require_once $_SERVER['DOCUMENT_ROOT'] . '/src/middleware/subscription.php';
// $subHeroState, $subRemaining и т.д. теперь доступны
```
Если проверка не прошла — middleware делает `header('Location: ...')` и `exit`.

---

## Основные потоки данных

### Поток 1: Анализ (главная фича)
```
Пользователь открывает /analysis/
  → auth.php + subscription.php проверяют доступ
  → AnalysisRepository::createSession() создаёт запись в БД
  → Страница рендерит chat-roller в режиме 'analysis'
  → Пользователь отправляет сообщение
  → features/chat-roller/api/send-message.php
  → AIService::sendMessage() → OpenAI/Claude/DeepSeek
  → Supervisor mode: ответ ждёт одобрения администратора
  → После одобрения — пользователь видит ответ
  → При завершении: ProfileService::updateProfile() обновляет психологический профиль
  → MeditationService::scheduleGeneration() ставит медитацию в очередь
```

### Поток 2: Генерация медитации (фоновый процесс)
```
MeditationService::scheduleGeneration() создаёт pending-запись
  → Запускает process-meditations.php как background worker
  → generateTextFromAI(): ИИ пишет текст медитации (название, описание, аудио-текст)
  → ElevenLabsService::generateSpeech(): текст → MP3 файл
  → ImageGenerationService::generate(): изображение для медитации
  → Запись помечается как ready
  → Пользователь видит медитацию на /meditations/
```

### Поток 3: Оплата
```
Пользователь выбирает тариф на /billing/
  → PaymentService::detectProvider(): по IP определяет YooKassa или Stripe
  → Создаётся платёж, пользователь редиректится на платёжную страницу
  → После оплаты провайдер присылает webhook
  → PaymentService::handleWebhook() верифицирует подпись
  → PaymentService::activateSubscription() создаёт запись в subscriptions
```

---

## Файловая структура страниц

Каждая страница — отдельная папка в `pages/`:
```
pages/dashboard/
├── index.php       # Контроллер + HTML шаблон
├── dashboard.css   # Стили, специфичные для страницы
└── dashboard.js    # JS, специфичный для страницы
```

API-эндпоинты страниц лежат в подпапке `api/`:
```
pages/admin/settings/
├── index.php
└── api/
    └── save.php    # POST-эндпоинт сохранения настроек
```

---

## Supervisor Mode — ручная модерация ИИ

Когда `SUPERVISOR_MODE = true` (флаг в `app_settings`):
1. ИИ генерирует ответ
2. Ответ сохраняется в `messages` со статусом `pending_review`
3. Пользователь видит "загрузка..." пока ответ не одобрен
4. Администратор в `/admin/` видит очередь на модерацию
5. При одобрении (можно отредактировать текст) статус меняется на `approved`
6. Фронтенд (chat-roller) polling'ом обнаруживает новое сообщение

Это позволяет контролировать качество ответов на ранних этапах.
