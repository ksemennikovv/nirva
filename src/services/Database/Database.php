<?php
/**
 * src/services/Database/Database.php — Сервис подключения к базе данных.
 *
 * Отвечает исключительно за создание и хранение PDO-соединения.
 * Использует паттерн Singleton: соединение создаётся один раз за запрос
 * и переиспользуется всеми частями приложения через Database::getConnection().
 *
 * Зависимости (должны быть подключены до этого файла):
 *   - config/app.php     → настройки логирования ошибок (error_log)
 *   - config/database.php → константы DB_HOST, DB_NAME, DB_USER, DB_PASS
 *
 * Запрещено добавлять в этот класс:
 *   - SQL-запросы
 *   - бизнес-логику
 *   - AI-логику
 */
class Database
{
    /**
     * $connection — единственный экземпляр PDO-соединения (Singleton).
     *
     * static — свойство принадлежит классу, а не объекту.
     * ?PDO   — может быть null (до первого вызова getConnection())
     *          или объектом PDO (после установки соединения).
     * private — недоступно снаружи класса напрямую;
     *           получить соединение можно только через getConnection().
     */
    private static ?PDO $connection = null;

    /**
     * getConnection() — возвращает активное PDO-соединение с базой данных.
     *
     * Принцип работы (Singleton):
     *   1. При первом вызове: $connection === null → создаёт новое PDO-соединение.
     *   2. При повторных вызовах: возвращает уже созданное соединение без переподключения.
     *
     * Это гарантирует, что за один HTTP-запрос открывается ровно одно соединение с БД,
     * что экономит ресурсы и предотвращает утечки соединений.
     *
     * @return PDO  Готовое к использованию PDO-соединение.
     * @throws PDOException  Если подключение не удалось (пробрасывается наверх).
     */
    public static function getConnection(): PDO
    {
        // Проверяем: соединение уже создано? Если нет — создаём.
        if (self::$connection === null) {
            try {
                /**
                 * Создаём новый объект PDO — это и есть само соединение с БД.
                 *
                 * DSN (Data Source Name) — строка подключения:
                 *   mysql:          → драйвер MySQL
                 *   host=DB_HOST    → хост сервера из config/database.php
                 *   dbname=DB_NAME  → имя базы данных из config/database.php
                 *   charset=utf8mb4 → кодировка UTF-8 с поддержкой emoji (4 байта)
                 *
                 * DB_USER, DB_PASS — учётные данные из config/database.php.
                 */
                self::$connection = new PDO(
                    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                    DB_USER,
                    DB_PASS,
                    [
                        /**
                         * ERRMODE_EXCEPTION → при ошибке SQL бросает PDOException.
                         * Без этой опции PDO молча возвращает false — ошибки легко пропустить.
                         */
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

                        /**
                         * FETCH_ASSOC → fetch() возвращает ассоциативный массив ['column' => value].
                         * Без этой опции возвращается и числовой, и ассоциативный индекс — дублирование данных.
                         */
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                );
            } catch (PDOException $e) {
                /**
                 * Если подключение не удалось (неверный хост, пароль, БД не существует и т.д.):
                 *
                 * error_log() → записывает сообщение об ошибке в /logs/php-errors.log
                 *               (путь задан в config/app.php через ini_set).
                 *               Пользователь текст ошибки не видит — это безопасно.
                 *
                 * throw $e   → пробрасывает исключение выше по стеку вызовов.
                 *               В будущем можно поймать в pages/index.php и показать
                 *               пользователю страницу «Сервис временно недоступен».
                 */
                error_log('Database connection error: ' . $e->getMessage());
                throw $e;
            }
        }

        // Возвращаем соединение — новое (только что созданное) или ранее сохранённое.
        return self::$connection;
    }
}
