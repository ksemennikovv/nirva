CREATE TABLE IF NOT EXISTS practices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(100) UNIQUE NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  video_url VARCHAR(500),
  sort_order SMALLINT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO practices (slug, title, description, sort_order) VALUES
  ('shapka-monomakha', 'Шапка Мономаха', 'Упражнение на принятие ответственности и лидерство. Помогает выйти из позиции жертвы и взять власть над своей жизнью.', 1),
  ('zerkalo', 'Зеркало', 'Практика принятия своего отражения и работы с внутренним критиком. Развивает самосострадание.', 2),
  ('koren', 'Корень', 'Заземление через тело — работа с тревогой, нестабильностью и отрывом от настоящего момента.', 3),
  ('otkrytoe-serdtse', 'Открытое сердце', 'Практика уязвимости и доверия в отношениях. Помогает раскрыться и снизить защитные реакции.', 4),
  ('vnutrenniy-rebenok', 'Внутренний ребёнок', 'Работа с детскими паттернами и непрожитыми эмоциями. Восстанавливает контакт с собой.', 5);
