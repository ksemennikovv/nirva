# Nirva — Карта проекта для Claude

## Что это за проект

Nirva — это веб-платформа для осознанности и психологической работы. Пользователи могут разбирать свои сны и переживания с помощью ИИ, слушать персональные медитации, вести дневник и отслеживать психологический профиль. Проект работает на PHP + MySQL, интерфейс на vanilla JS/CSS.

## Стек

- **Backend**: PHP (без фреймворка), PDO + MySQL
- **Frontend**: Vanilla JS, CSS Custom Properties
- **ИИ**: OpenAI GPT-4o (основной), Claude, DeepSeek (переключаемые)
- **Аудио**: ElevenLabs TTS через Cloudflare Worker proxy
- **Изображения**: Fal.ai (Flux), NanoBanana (Gemini Imagen)
- **Платежи**: YooKassa (RU), Stripe (международные)
- **Сервер**: Apache + .htaccess роутинг

## Как устроен роутинг

`.htaccess` маппит чистые URL на PHP-файлы:
- `/dashboard/` → `pages/dashboard/index.php`
- `/analysis/` → `pages/analysis/index.php`
- `/admin/settings/` → `pages/admin/settings/index.php`
- API-эндпоинты типа `/api/meditations/...` → `assets/php/...`

Точка входа — `index.php`: он стартует сессию, подключает конфиги, устанавливает обработку ошибок.

## Структура проекта

```
public_html/
├── config/           # Конфигурация (app, database, ai, business)
├── src/
│   ├── middleware/   # Auth, Admin, Subscription проверки
│   ├── repositories/ # Весь доступ к БД (паттерн Repository)
│   └── services/     # Бизнес-логика (AI, платежи, медитации, профиль)
├── pages/            # Страницы — каждая папка = один URL
├── features/         # Переиспользуемые UI-компоненты (чат, плеер)
├── assets/           # CSS, JS, изображения, аудио
├── database/
│   └── migrations/   # SQL-миграции (запускать вручную по порядку)
└── docs/             # Документация проекта (читать здесь)
```

## Ключевые архитектурные принципы

1. **Repository pattern** — весь SQL только в `src/repositories/`. Страницы и сервисы не делают прямых запросов к БД.
2. **Singleton Database** — одно PDO-соединение на весь запрос (`src/services/Database/Database.php`).
3. **BusinessConfig** — динамические настройки читаются из таблицы `app_settings` (`config/business.php`). Константы из файлов — лишь дефолты.
4. **Middleware включением** — страницы включают нужные middleware через `require`: auth.php, subscription.php, admin.php.
5. **Supervisor mode** — все ответы ИИ по умолчанию проходят ручную модерацию (флаг в app_settings).

## Документация

Полная документация находится в `docs/`:
- [docs/INDEX.md](docs/INDEX.md) — главный индекс, читать первым
- [docs/architecture/overview.md](docs/architecture/overview.md) — архитектура и потоки данных
- [docs/architecture/database.md](docs/architecture/database.md) — схема БД
- [docs/architecture/ai-system.md](docs/architecture/ai-system.md) — ИИ-система
- [docs/features/](docs/features/) — описание каждой фичи
- [docs/components/](docs/components/) — переиспользуемые компоненты

## Конвенции

- Комментарии и commit-сообщения — на русском
- Классы: PascalCase, файлы: PascalCase.php
- БД-таблицы: snake_case, столбцы: snake_case
- CSS-переменные: `--color-primary`, классы: `nirva-`, `adm-`
- Новые настройки добавлять в `app_settings` через миграцию, не хардкодить

## Автоматизация

При каждом коммите `.claude/auto-commit.ps1` автоматически обновляет `docs/` на основе изменённых файлов через вызов Claude CLI.
