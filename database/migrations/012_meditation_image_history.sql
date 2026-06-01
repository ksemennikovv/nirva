CREATE TABLE IF NOT EXISTS meditation_image_history (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meditation_id INT UNSIGNED NOT NULL,
  image_url     VARCHAR(500) NOT NULL,
  source        ENUM('generated','uploaded','url') DEFAULT 'generated',
  provider      VARCHAR(50) NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_meditation (meditation_id)
);
