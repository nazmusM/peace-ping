-- Account-Based Architecture Migration for Peace Ping
-- Idempotent migration - checks before creating/modifying

-- Create new users table
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    contact_encrypted TEXT NOT NULL,
    contact_hash CHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_contact_hash (contact_hash),
    INDEX idx_users_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add name column to users table if it doesn't exist (for existing tables)
SET @dbname = DATABASE();
SET @tablename = 'users';
SET @columnname = 'name';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' VARCHAR(120) NOT NULL AFTER id;')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add user_id to pings if not exists
SET @dbname = DATABASE();
SET @tablename = 'pings';
SET @columnname = 'user_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_schema = @dbname)
      AND (table_name = @tablename)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' BIGINT UNSIGNED NOT NULL AFTER id, ADD INDEX idx_pings_user_id (user_id);')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add user_a_id to matches if not exists
SET @columnname = 'user_a_id';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = @dbname AND table_name = 'matches' AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE matches ADD COLUMN ', @columnname, ' BIGINT UNSIGNED NULL AFTER fingerprint_b, ADD INDEX idx_matches_user_a (user_a_id);')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add user_b_id to matches if not exists
SET @columnname = 'user_b_id';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = @dbname AND table_name = 'matches' AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE matches ADD COLUMN ', @columnname, ' BIGINT UNSIGNED NULL AFTER user_a_id, ADD INDEX idx_matches_user_b (user_b_id);')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add user_id to preferences if not exists
SET @tablename = 'preferences';
SET @columnname = 'user_id';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = @dbname AND table_name = @tablename AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' BIGINT UNSIGNED NOT NULL AFTER match_id, ADD INDEX idx_preferences_user_id (user_id);')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add user_id to rate_limits if not exists
SET @tablename = 'rate_limits';
SET @columnname = 'user_id';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = @dbname AND table_name = @tablename AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' BIGINT UNSIGNED NULL AFTER ip_hash, ADD INDEX idx_rate_limits_user_id (user_id);')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Create notifications table if not exists
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    fingerprint_recipient CHAR(64) NOT NULL,
    type VARCHAR(32) NOT NULL,
    message TEXT NOT NULL,
    delivered TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notifications_unique (match_id, fingerprint_recipient, type),
    INDEX idx_notifications_match_id (match_id),
    INDEX idx_notifications_user_id (user_id),
    INDEX idx_notifications_recipient (fingerprint_recipient),
    INDEX idx_notifications_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign keys if not exist (constraints will fail if already exists)
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE table_schema = @dbname AND table_name = 'pings' AND constraint_name = 'fk_pings_user') > 0,
  'SELECT 1',
  'ALTER TABLE pings ADD CONSTRAINT fk_pings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;'
));
PREPARE addConstraintIfExists FROM @preparedStatement;
EXECUTE addConstraintIfExists;
DEALLOCATE PREPARE addConstraintIfExists;

SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE table_schema = @dbname AND table_name = 'matches' AND constraint_name = 'fk_matches_user_a') > 0,
  'SELECT 1',
  'ALTER TABLE matches ADD CONSTRAINT fk_matches_user_a FOREIGN KEY (user_a_id) REFERENCES users (id) ON DELETE SET NULL;'
));
PREPARE addConstraintIfExists FROM @preparedStatement;
EXECUTE addConstraintIfExists;
DEALLOCATE PREPARE addConstraintIfExists;

SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE table_schema = @dbname AND table_name = 'matches' AND constraint_name = 'fk_matches_user_b') > 0,
  'SELECT 1',
  'ALTER TABLE matches ADD CONSTRAINT fk_matches_user_b FOREIGN KEY (user_b_id) REFERENCES users (id) ON DELETE SET NULL;'
));
PREPARE addConstraintIfExists FROM @preparedStatement;
EXECUTE addConstraintIfExists;
DEALLOCATE PREPARE addConstraintIfExists;

SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE table_schema = @dbname AND table_name = 'preferences' AND constraint_name = 'fk_preferences_user') > 0,
  'SELECT 1',
  'ALTER TABLE preferences ADD CONSTRAINT fk_preferences_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;'
));
PREPARE addConstraintIfExists FROM @preparedStatement;
EXECUTE addConstraintIfExists;
DEALLOCATE PREPARE addConstraintIfExists;

SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE table_schema = @dbname AND table_name = 'rate_limits' AND constraint_name = 'fk_rate_limits_user') > 0,
  'SELECT 1',
  'ALTER TABLE rate_limits ADD CONSTRAINT fk_rate_limits_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;'
));
PREPARE addConstraintIfExists FROM @preparedStatement;
EXECUTE addConstraintIfExists;
DEALLOCATE PREPARE addConstraintIfExists;

SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE table_schema = @dbname AND table_name = 'notifications' AND constraint_name = 'fk_notifications_match') > 0,
  'SELECT 1',
  'ALTER TABLE notifications ADD CONSTRAINT fk_notifications_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE;'
));
PREPARE addConstraintIfExists FROM @preparedStatement;
EXECUTE addConstraintIfExists;
DEALLOCATE PREPARE addConstraintIfExists;

SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE table_schema = @dbname AND table_name = 'notifications' AND constraint_name = 'fk_notifications_user') > 0,
  'SELECT 1',
  'ALTER TABLE notifications ADD CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;'
));
PREPARE addConstraintIfExists FROM @preparedStatement;
EXECUTE addConstraintIfExists;
DEALLOCATE PREPARE addConstraintIfExists;
