# База данных Nirva

## Общее

MySQL/MariaDB. Все миграции в `database/migrations/` — файлы нумерованные, запускаются вручную по порядку. Основное соединение через Singleton: `src/services/Database/Database.php`.

---

## Таблицы

### `users` — пользователи
| Столбец | Тип | Назначение |
|---------|-----|-----------|
| id | INT | PK |
| email | VARCHAR | Уникальный email |
| password_hash | VARCHAR | bcrypt, nullable (можно без пароля) |
| role | ENUM | 'user' или 'admin' |
| created_at | TIMESTAMP | Дата регистрации |

Репозиторий: `src/repositories/UserRepository.php`

---

### `analysis_sessions` — сессии анализа
Главная таблица продукта. Каждая сессия — один диалог с ИИ по разбору сна/переживания.

| Столбец | Тип | Назначение |
|---------|-----|-----------|
| id | INT | PK |
| user_id | INT | FK → users |
| current_state | VARCHAR | Состояние машины состояний диалога |
| topic | TEXT | Тема/краткое описание (заполняется ИИ) |
| practice | VARCHAR | Выбранная практика |
| summary | TEXT | Итоговый анализ (заполняется при завершении) |
| task | TEXT | Задание после анализа |
| dialogue_stage | INT | Текущий этап диалога |
| dialogue_summary | TEXT | Промежуточное резюме диалога для контекста ИИ |
| risk_level | ENUM | Уровень кризисности (none/low/medium/high) |
| status | ENUM | pending / active / completed / abandoned |
| created_at / updated_at | TIMESTAMP | |

Репозиторий: `src/repositories/AnalysisRepository.php`

---

### `messages` — история сообщений чата
Хранит все сообщения для всех типов диалогов (анализ, рефлексия, дневник).

| Столбец | Тип | Назначение |
|---------|-----|-----------|
| id | INT | PK |
| session_id | INT | ID сессии (анализа или дневника) |
| role | ENUM | 'user' или 'assistant' |
| content | TEXT | Текст сообщения |
| phase | VARCHAR | Фаза диалога (analysis, reflection, diary) |
| review_status | ENUM | pending_review / approved / rejected |
| reviewed_content | TEXT | Отредактированный текст (supervisor mode) |
| created_at | TIMESTAMP | |

Репозиторий: `src/repositories/MessageRepository.php`

---

### `meditations` — медитации
| Столбец | Тип | Назначение |
|---------|-----|-----------|
| id | INT | PK |
| user_id | INT | FK → users (null для общих медитаций) |
| analysis_session_id | INT | FK → analysis_sessions (null для общих) |
| title | VARCHAR | Название медитации |
| description | TEXT | Описание |
| context | TEXT | Контекст/тема для генерации |
| audio_text | TEXT | Текст для озвучки (ElevenLabs) |
| audio_url | VARCHAR | Путь к MP3 файлу |
| image_url | VARCHAR | Путь к изображению |
| status | ENUM | pending / generating / ready / failed |
| source_type | ENUM | personal / general |
| category | VARCHAR | Категория (для общих медитаций) |
| expires_at | TIMESTAMP | Когда истекает бесплатный доступ |
| created_at | TIMESTAMP | |

Репозиторий: `src/repositories/MeditationRepository.php`

---

### `subscriptions` — подписки
| Столбец | Тип | Назначение |
|---------|-----|-----------|
| id | INT | PK |
| user_id | INT | FK → users |
| plan | ENUM | start / basic / transformation |
| period | ENUM | monthly / semi-annual / annual |
| status | ENUM | active / expired / cancelled |
| analyses_used | INT | Использовано анализов в текущем периоде |
| started_at / expires_at | TIMESTAMP | Даты действия |

Репозиторий: `src/repositories/SubscriptionRepository.php`

---

### `payments` — платежи
| Столбец | Тип | Назначение |
|---------|-----|-----------|
| id | INT | PK |
| user_id | INT | FK → users |
| subscription_id | INT | FK → subscriptions |
| provider | ENUM | yookassa / stripe |
| provider_payment_id | VARCHAR | ID платежа во внешней системе |
| amount | DECIMAL | Сумма в рублях |
| status | ENUM | pending / succeeded / failed |
| plan / period | VARCHAR | Что оплачивали |
| created_at | TIMESTAMP | |

Репозиторий: `src/repositories/PaymentRepository.php`

---

### `diary_entries` — записи дневника
| Столбец | Тип | Назначение |
|---------|-----|-----------|
| id | INT | PK |
| user_id | INT | FK → users |
| summary | TEXT | Итоговое резюме разговора |
| status | ENUM | active / completed |
| created_at | TIMESTAMP | |

Репозиторий: `src/repositories/DiaryRepository.php`. Сообщения дневника хранятся в `messages` (phase='diary').

---

### `app_settings` — динамические настройки
Ключ-значение хранилище для всех настроек, которые нужно менять без деплоя.

| Столбец | Назначение |
|---------|-----------|
| key | Уникальный ключ настройки |
| value | Текстовое значение |
| description | Пояснение что это |

Примеры ключей:
- `supervisor_mode` — включить модерацию ответов ИИ
- `subscription_required` — включить пейвол
- `image_provider` — flux / gemini / none
- `image_model` — конкретная модель
- `meditation_auto_generate` — yes/no
- `ai_provider_analysis`, `ai_model_analysis` — ИИ для анализа

Класс для чтения: `BusinessConfig::setting()` в `config/business.php`
Репозиторий: `src/repositories/AppSettingsRepository.php`

---

### `profile_parameters` и `profile_options` — определения параметров профиля
Статические справочники (не меняются пользователем):
- `profile_parameters` — список параметров (код, название, категория, тип: fixed/free)
- `profile_options` — допустимые значения для fixed-параметров

### `user_profile_values` — значения профиля пользователя
Текущие значения психологического профиля (обновляются ИИ после каждого анализа).

### `profile_history` — история изменений профиля
Лог: когда, какой параметр, старое и новое значение, откуда изменение (analysis/diary).

### `profile_memories` — наблюдения ИИ
Свободные текстовые наблюдения ИИ о пользователе, не вписывающиеся в параметры. Имеют поле `importance` (0-1).

Репозиторий для всех профильных таблиц: `src/repositories/ProfileParameterRepository.php`

---

### `verification_codes` — коды верификации email
| Столбец | Назначение |
|---------|-----------|
| email | Адрес куда отправлен код |
| code | 6-значный код |
| expires_at | Время истечения |
| used_at | Когда использован (null = не использован) |

Репозиторий: `src/repositories/AuthCodeRepository.php`

---

### `meditation_listens` — история прослушиваний
Лог: кто, какую медитацию, когда слушал и сколько секунд.

### `meditation_purchases` — купленные медитации
Записи о покупке медитаций сверх бесплатного доступа.

### `practices` — практики пользователей
Практики, к которым у пользователя есть доступ (выдаются после анализа).

### `background_jobs` — очередь фоновых задач
Используется для отслеживания запущенных задач генерации медитаций.

---

## Схема связей (упрощённо)

```
users
  ├── subscriptions (1:N)
  ├── payments (1:N)
  ├── analysis_sessions (1:N)
  │     └── messages (1:N)
  │     └── meditations (1:N)
  ├── diary_entries (1:N)
  │     └── messages (через session_id, phase='diary')
  ├── user_profile_values (1:N) → profile_parameters
  ├── profile_memories (1:N)
  └── meditation_listens (1:N)
```
