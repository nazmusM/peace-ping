-- Complete Peace Ping Database Schema with Account-Based Architecture
-- This file represents the full current schema including all migrations

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    contact_encrypted TEXT NOT NULL,
    contact_hash CHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_contact_hash (contact_hash),
    INDEX idx_users_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    self_name VARCHAR(120) NOT NULL,
    fingerprint_self CHAR(64) NOT NULL,
    fingerprint_target CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pings_pair (fingerprint_self, fingerprint_target),
    INDEX idx_pings_user_id (user_id),
    INDEX idx_pings_fingerprint_self (fingerprint_self),
    INDEX idx_pings_fingerprint_target (fingerprint_target),
    INDEX idx_pings_created_at (created_at),
    CONSTRAINT fk_pings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fingerprint_a CHAR(64) NOT NULL,
    fingerprint_b CHAR(64) NOT NULL,
    user_a_id BIGINT UNSIGNED NULL,
    user_b_id BIGINT UNSIGNED NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'awaiting_preferences',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_matches_pair (fingerprint_a, fingerprint_b),
    INDEX idx_matches_fingerprint_a (fingerprint_a),
    INDEX idx_matches_fingerprint_b (fingerprint_b),
    INDEX idx_matches_user_a (user_a_id),
    INDEX idx_matches_user_b (user_b_id),
    INDEX idx_matches_created_at (created_at),
    CONSTRAINT fk_matches_user_a FOREIGN KEY (user_a_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_matches_user_b FOREIGN KEY (user_b_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    preference ENUM('reach_out', 'prefer_other', 'either') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_preferences_match_fingerprint (match_id, fingerprint),
    INDEX idx_preferences_match_id (match_id),
    INDEX idx_preferences_user_id (user_id),
    INDEX idx_preferences_created_at (created_at),
    CONSTRAINT fk_preferences_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE,
    CONSTRAINT fk_preferences_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS rate_limits (
    ip_hash CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    window_start DATETIME NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ip_hash, window_start),
    INDEX idx_rate_limits_user_id (user_id),
    INDEX idx_rate_limits_created_at (created_at),
    CONSTRAINT fk_rate_limits_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
