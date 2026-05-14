-- Peace Ping database schema
-- Canonical schema for fresh installs and local resets.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS sms_inbox;
DROP TABLE IF EXISTS match_tokens;
DROP TABLE IF EXISTS match_preferences;
DROP TABLE IF EXISTS preferences;
DROP TABLE IF EXISTS pings;
DROP TABLE IF EXISTS matches;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS rate_limits;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash VARCHAR(64) NOT NULL,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    request_count INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rate_limits_ip_hash_window (ip_hash, window_start),
    INDEX idx_rate_limits_ip_hash (ip_hash),
    INDEX idx_rate_limits_window_start (window_start),
    INDEX idx_rate_limits_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    contact_encrypted TEXT NOT NULL,
    contact_hash CHAR(64) NOT NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    verification_code VARCHAR(6) NULL,
    verification_expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_contact_hash (contact_hash),
    INDEX idx_users_contact_hash (contact_hash),
    INDEX idx_users_is_verified (is_verified),
    INDEX idx_users_verification_code (verification_code),
    INDEX idx_users_verification_expires_at (verification_expires_at),
    INDEX idx_users_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    fingerprint_self CHAR(64) NOT NULL DEFAULT '',
    fingerprint_target CHAR(64) NOT NULL,
    target_masked VARCHAR(40) NULL,
    recipient_name VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pings_pair (fingerprint_self, fingerprint_target),
    INDEX idx_pings_user_id (user_id),
    INDEX idx_pings_fingerprint_self (fingerprint_self),
    INDEX idx_pings_fingerprint_target (fingerprint_target),
    INDEX idx_pings_target_masked (target_masked),
    INDEX idx_pings_created_at (created_at),
    CONSTRAINT fk_pings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE matches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fingerprint_a CHAR(64) NOT NULL,
    fingerprint_b CHAR(64) NOT NULL,
    user_a_id BIGINT UNSIGNED NULL,
    user_b_id BIGINT UNSIGNED NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'awaiting_preferences',
    stage INT NOT NULL DEFAULT 1,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_matches_pair (fingerprint_a, fingerprint_b),
    INDEX idx_matches_fingerprint_a (fingerprint_a),
    INDEX idx_matches_fingerprint_b (fingerprint_b),
    INDEX idx_matches_user_a_id (user_a_id),
    INDEX idx_matches_user_b_id (user_b_id),
    INDEX idx_matches_status (status),
    INDEX idx_matches_stage (stage),
    INDEX idx_matches_created_at (created_at),
    CONSTRAINT fk_matches_user_a FOREIGN KEY (user_a_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_matches_user_b FOREIGN KEY (user_b_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE match_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    token CHAR(64) NOT NULL,
    is_used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    UNIQUE KEY uq_match_tokens_token (token),
    INDEX idx_match_tokens_match_id (match_id),
    INDEX idx_match_tokens_user_id (user_id),
    INDEX idx_match_tokens_token (token),
    INDEX idx_match_tokens_is_used (is_used),
    INDEX idx_match_tokens_expires_at (expires_at),
    CONSTRAINT fk_match_tokens_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
    CONSTRAINT fk_match_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    fingerprint CHAR(64) NULL,
    preference VARCHAR(50) NOT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_preferences_user_match (match_id, user_id),
    UNIQUE KEY uq_preferences_fingerprint_match (match_id, fingerprint),
    INDEX idx_preferences_match_id (match_id),
    INDEX idx_preferences_user_id (user_id),
    INDEX idx_preferences_fingerprint (fingerprint),
    INDEX idx_preferences_submitted_at (submitted_at),
    INDEX idx_preferences_created_at (created_at),
    CONSTRAINT fk_preferences_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
    CONSTRAINT fk_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE match_preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    preference VARCHAR(50) NOT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_match_preferences_user_match (match_id, user_id),
    INDEX idx_match_preferences_match_id (match_id),
    INDEX idx_match_preferences_user_id (user_id),
    INDEX idx_match_preferences_submitted_at (submitted_at),
    CONSTRAINT fk_match_preferences_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
    CONSTRAINT fk_match_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sms_inbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    phone_number VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    status ENUM('queued', 'sent', 'delivered', 'received', 'failed') NOT NULL DEFAULT 'queued',
    external_id VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sms_inbox_user_id (user_id),
    INDEX idx_sms_inbox_phone_number (phone_number),
    INDEX idx_sms_inbox_status (status),
    INDEX idx_sms_inbox_created_at (created_at),
    CONSTRAINT fk_sms_inbox_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Peace Ping schema created successfully.' AS message;
