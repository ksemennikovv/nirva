# Фича: Авторизация (/login/)

## Что это

Простая авторизация через email. Поддерживает два режима: без пароля (magic link / email-код) и с паролем. Регистрация происходит автоматически при первом входе.

---

## Ключевые файлы

| Файл | Роль |
|------|------|
| `pages/login/index.php` | Страница входа |
| `src/repositories/UserRepository.php` | Создание и поиск пользователей |
| `src/repositories/AuthCodeRepository.php` | Коды верификации |
| `src/middleware/auth.php` | Проверка авторизации на защищённых страницах |

---

## Поток авторизации

### Вход через код (email)
1. Пользователь вводит email
2. Генерируется 6-значный код, сохраняется в `verification_codes` с `expires_at`
3. Код отправляется на email
4. Пользователь вводит код
5. `AuthCodeRepository::findActiveByEmailAndCode()` — проверяет код
6. Если пользователь не существует — `UserRepository::create()` создаёт аккаунт
7. `$_SESSION['user_id']` устанавливается
8. Код помечается использованным (`markAsUsed()`)

### Вход с паролем
Если у пользователя установлен пароль (`UserRepository::hasPassword()`):
- `UserRepository::verifyPassword()` — bcrypt проверка

### Rate limiting
`AuthCodeRepository::findLastByEmail()` — проверяет когда последний раз отправляли код, защита от спама.

---

## Middleware

`src/middleware/auth.php` подключается в начале каждой защищённой страницы:
- Читает `$_SESSION['user_id']`
- Если нет — `header('Location: /login/')` + `exit`
- Если есть — устанавливает `$currentUserId` для использования на странице

`src/middleware/admin.php` — дополнительно проверяет `role = 'admin'`:
- Читает пользователя из БД
- Не-admin → 403 или редирект
- Устанавливает `$adminUser`
