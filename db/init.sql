CREATE DATABASE IF NOT EXISTS weather_climate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE weather_climate;
CREATE TABLE IF NOT EXISTS weather_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  city VARCHAR(160) NOT NULL,
  country VARCHAR(120) NOT NULL DEFAULT '',
  temperature DECIMAL(5,2) NOT NULL,
  weather_description VARCHAR(120) NOT NULL,
  climate_description VARCHAR(160) NOT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_requested_at (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
