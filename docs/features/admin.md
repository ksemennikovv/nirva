# Фича: Админ-панель (/admin/)

## Что это

Внутренний инструмент для управления платформой. Основные задачи: модерация ответов ИИ (supervisor mode), управление пользователями, настройка ИИ-провайдеров и параметров генерации медитаций.

Доступ: только пользователи с `role = 'admin'` в таблице `users`. Middleware: `src/middleware/admin.php`.

---

## Разделы админки

### Очередь модерации (`/admin/`)
Главный экран при включённом supervisor mode:
- Показывает все сообщения со статусом `pending_review`
- Администратор видит текст ответа ИИ
- Может отредактировать текст и нажать "Одобрить" или "Отклонить"
- При одобрении: `MessageRepository::approveMessage($id, $reviewedContent)`
- Пользователь сразу видит ответ (chat-roller polling)

### Пользователи (`/admin/users/`)
- Список всех пользователей с пагинацией и поиском
- Детали: подписка, количество анализов, последняя активность
- Действия: изменить email, изменить роль (admin/user), удалить

Репозиторий: `UserRepository::getAll()`, `getWithDetails()`.

### Настройки (`/admin/settings/`)
Ключевая страница — управляет поведением всей платформы через `app_settings`:

**Секции настроек:**
1. **Общие** — supervisor_mode, subscription_required
2. **ИИ для диалога** — провайдер и модель для каждого режима (analysis, diary, meditation)
3. **Генерация изображений** — провайдер (flux/gemini/none), модель, стиль
4. **Аудио** — ElevenLabs параметры
5. **Бизнес** — лимиты, автогенерация медитаций

Сохранение через `POST /admin/settings/api/save.php` — whitelist допустимых ключей, валидация типов.

### Медитации (`/admin/meditations/`)
- Список всех медитаций с их статусами
- Регенерация изображения: вызывает `ImageGenerationService::generate()`
- История версий изображений: `ImageHistoryService::getHistory()` + откат
- Просмотр и редактирование текста медитации

---

## Ключевые файлы

| Файл | Роль |
|------|------|
| `src/middleware/admin.php` | Проверка роли admin, загрузка $adminUser |
| `pages/admin/index.php` | Очередь модерации |
| `pages/admin/users/index.php` | Управление пользователями |
| `pages/admin/settings/index.php` | Настройки платформы |
| `pages/admin/settings/api/save.php` | Сохранение настроек |
| `pages/admin/meditations/` | Управление медитациями |
| `pages/admin/admin.css` | Стили админки (отдельные от основных) |

---

## Как устроено сохранение настроек

`pages/admin/settings/api/save.php` — POST эндпоинт:
1. Проверяет admin middleware
2. Принимает JSON с ключами и значениями
3. Фильтрует через **whitelist** допустимых ключей (защита от произвольной записи)
4. Валидирует тип каждого значения (`string`, `bool`, `number`)
5. Сохраняет через `AppSettingsRepository::set()`
6. `BusinessConfig` сбрасывает кеш — следующий запрос читает новые значения

---

## Добавление новой настройки

Чтобы добавить новый настраиваемый параметр:
1. Создать миграцию `database/migrations/NNN_...sql` с `INSERT IGNORE INTO app_settings`
2. Добавить ключ в whitelist в `pages/admin/settings/api/save.php`
3. Добавить UI-элемент в `pages/admin/settings/index.php`
4. В коде читать через `BusinessConfig::setting('ключ', 'дефолт')`
