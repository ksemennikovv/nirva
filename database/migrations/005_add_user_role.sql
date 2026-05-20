-- Migration 005: добавить роль пользователя для админ-панели
ALTER TABLE users ADD COLUMN role ENUM('user', 'admin') NOT NULL DEFAULT 'user' AFTER email;

-- Назначить первого пользователя (id=3) администратором
-- Измените id при необходимости
UPDATE users SET role = 'admin' WHERE id = 3;
