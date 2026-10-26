-- Run this once inside the MySQL/MariaDB database created in Plesk.
-- Use the database selected in phpMyAdmin before importing.
-- All stored times are UTC, as set by the PHP application.
CREATE TABLE IF NOT EXISTS guidance_enquiries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  contact_number VARCHAR(30) NOT NULL,
  email VARCHAR(254) NOT NULL,
  topic VARCHAR(80) NOT NULL,
  message TEXT NOT NULL,
  consent_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_created_at (created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
