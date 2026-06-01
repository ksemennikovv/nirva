# Фича: Анализ переживаний (/analysis/)

## Что это и зачем

Главная фича продукта. Пользователь рассказывает сон, тревогу, эмоцию или любую психологически значимую ситуацию. ИИ проводит глубинный диалог: задаёт уточняющие вопросы, помогает увидеть паттерны, предлагает практику. В конце формируется письменный анализ, обновляется психологический профиль, и автоматически создаётся персональная медитация.

Это "слот" — на базовом тарифе 2 анализа в месяц, слоты сгорают через 30 дней если не использованы.

---

## Ключевые файлы

| Файл | Роль |
|------|------|
| `pages/analysis/index.php` | Контроллер страницы, создаёт сессию, рендерит интерфейс |
| `src/repositories/AnalysisRepository.php` | CRUD для сессий анализа |
| `src/repositories/MessageRepository.php` | CRUD для сообщений чата |
| `features/chat-roller/` | UI чата (переиспользуется) |
| `features/chat-roller/api/send-message.php` | Обработка каждого сообщения |
| `src/services/AI/AIService.php` | Запросы к ИИ |
| `src/services/Profile/ProfileService.php` | Обновление психологического профиля |
| `src/services/Meditation/MeditationService.php` | Запуск генерации медитации |
| `src/middleware/subscription.php` | Проверка доступа и лимитов |

---

## Как работает шаг за шагом

### 1. Открытие страницы
`pages/analysis/index.php`:
- Подключает `auth.php` (требует авторизации)
- Подключает `subscription.php` — вычисляет `$subHeroState`:
  - `1` — можно начать анализ
  - `2` — cooldown (слишком рано после предыдущего)
  - `3` — лимит исчерпан
  - `4` — нет подписки
- Если `$subHeroState == 1` — создаёт новую сессию через `AnalysisRepository::createSession()`
- Рендерит `chat-roller` с параметром `mode=analysis` и `session_id`

### 2. Диалог
Каждое сообщение пользователя идёт в `features/chat-roller/api/send-message.php`:
1. Загружает историю сообщений (`MessageRepository::getMessages()`)
2. Вызывает `AIService::sendMessage($history)` — ИИ учитывает весь контекст
3. Проверяет безопасность (`AIService::checkSafety()`) — определяет risk_level
4. Сохраняет ответ ИИ в `messages` со статусом `pending_review` (supervisor mode) или `approved`
5. Обновляет dialogue_stage если нужно

### 3. Контекст для ИИ на каждом шагу
В системный промпт ИИ передаётся:
- Психологический профиль пользователя (`ProfileService::formatForPrompt()`)
- Текущий этап диалога (dialogue_stage)
- Сжатое резюме предыдущих этапов (dialogue_summary)

Резюме позволяет не передавать весь длинный диалог целиком — только сжатую суть.

### 4. Завершение анализа
Когда ИИ решает что диалог завершён (или пользователь завершает вручную):
- `AnalysisRepository::completeSession()` — сохраняет summary и task
- `SubscriptionRepository::incrementUsed()` — тратит один слот
- `ProfileService::updateProfile()` — обновляет психологический профиль
- `MeditationService::scheduleGeneration()` — ставит медитацию в очередь

### 5. Рефлексия (фаза после анализа)
После анализа открывается фаза рефлексии — работа с практикой. Используется тот же чат, но с другим системным промптом. ИИ тот же (ai_provider_analysis). Завершается методом `AnalysisRepository::completeReflection()`.

---

## Supervisor mode в анализе

Если включён:
- Все ответы ИИ сохраняются как `pending_review`
- Фронтенд (chat-roller) делает polling каждые 2 секунды
- Пользователь видит анимацию "думаю..."
- Когда администратор одобряет — `approved`, фронтенд показывает ответ
- Администратор может отредактировать текст перед одобрением

---

## Машина состояний диалога

`current_state` в `analysis_sessions` хранит текущий этап:
- `greeting` — приветствие
- `exploration` — углубление в тему  
- `patterns` — выявление паттернов
- `insight` — формирование инсайта
- `practice_selection` — выбор практики
- `reflection` — рефлексия с практикой
- `completed` — завершено

Переходы управляются ИИ — он сам решает когда переходить к следующему этапу.

---

## Лимиты и тарифы

Определены в `config/business.php`:
- `start`: 1 анализ в месяц
- `basic`: 2 анализа в месяц
- `transformation`: 8 анализов в месяц
- Слоты сгорают через `BURN_PERIOD_DAYS` = 30 дней
- Минимальный интервал между анализами: `ANALYSIS_MIN_INTERVAL_DAYS` = 0 (нет ограничения)

---

## Что создаётся после анализа

1. Запись в `analysis_sessions` со статусом `completed`, summary, task
2. Обновлённый психологический профиль (`user_profile_values`)
3. Запись в `profile_history` — лог изменений
4. `profile_memories` — свободные наблюдения ИИ
5. Запись в `meditations` со статусом `pending` → фоновый процесс генерирует медитацию
