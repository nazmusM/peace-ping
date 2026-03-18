-- Account-Based Architecture Migration for Peace Ping
-- This migration adds user accounts while preserving existing functionality

-- Create new users table
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_encrypted TEXT NOT NULL,
    contact_hash CHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_contact_hash (contact_hash),
    INDEX idx_users_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add user_id column to pings table
ALTER TABLE pings 
ADD COLUMN user_id BIGINT UNSIGNED NOT NULL AFTER id,
ADD INDEX idx_pings_user_id (user_id);

-- Add user columns to matches table
ALTER TABLE matches 
ADD COLUMN user_a_id BIGINT UNSIGNED NULL AFTER fingerprint_b,
ADD COLUMN user_b_id BIGINT UNSIGNED NULL AFTER user_a_id,
ADD INDEX idx_matches_user_a (user_a_id),
ADD INDEX idx_matches_user_b (user_b_id);

-- Add user_id column to preferences table
ALTER TABLE preferences 
ADD COLUMN user_id BIGINT UNSIGNED NOT NULL AFTER match_id,
ADD INDEX idx_preferences_user_id (user_id);

-- Update rate_limits to work with users instead of IPs
ALTER TABLE rate_limits 
ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER ip_hash,
ADD INDEX idx_rate_limits_user_id (user_id);

-- Create notifications table for the NotificationService
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
    INDEX idx_notifications_created_at (created_at),
    CONSTRAINT fk_notifications_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Foreign key constraints
ALTER TABLE pings 
ADD CONSTRAINT fk_pings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;

ALTER TABLE matches 
ADD CONSTRAINT fk_matches_user_a FOREIGN KEY (user_a_id) REFERENCES users (id) ON DELETE SET NULL,
ADD CONSTRAINT fk_matches_user_b FOREIGN KEY (user_b_id) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE preferences 
ADD CONSTRAINT fk_preferences_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;

ALTER TABLE rate_limits 
ADD CONSTRAINT fk_rate_limits_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
