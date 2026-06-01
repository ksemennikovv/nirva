# Nirva — Главный индекс документации

> Начни отсюда. Этот файл — карта всего проекта.

## Архитектура

| Документ | Что внутри |
|----------|-----------|
| [architecture/overview.md](architecture/overview.md) | Стек, bootstrap-последовательность, паттерны, потоки данных |
| [architecture/database.md](architecture/database.md) | Схема БД: все таблицы, связи, назначение столбцов |
| [architecture/ai-system.md](architecture/ai-system.md) | Как устроена ИИ-система: провайдеры, режимы, промпты, supervisor mode |

## Фичи (бизнес-логика)

| Документ | Что описывает |
|----------|--------------|
| [features/analysis.md](features/analysis.md) | Разбор снов и переживаний — главная фича продукта |
| [features/meditations.md](features/meditations.md) | Генерация и воспроизведение персональных медитаций |
| [features/diary.md](features/diary.md) | Дневник — разговор с ИИ после анализа |
| [features/billing.md](features/billing.md) | Подписки, оплата (YooKassa/Stripe), тарифы |
| [features/admin.md](features/admin.md) | Админ-панель: пользователи, настройки, модерация |
| [features/profile.md](features/profile.md) | Психологический профиль пользователя |
| [features/auth.md](features/auth.md) | Авторизация через email (без пароля или с паролем) |

## Компоненты (переиспользуемые)

| Документ | Что описывает |
|----------|--------------|
| [components/chat-roller.md](components/chat-roller.md) | Чат-компонент — используется в анализе, дневнике, медитации |
| [components/med-player.md](components/med-player.md) | Плеер медитаций |
| [components/css-system.md](components/css-system.md) | Дизайн-система: цвета, типографика, компоненты UI |

## Changelog

Автоматически обновляется при каждом коммите:
- [changelog/](changelog/) — история изменений по датам
