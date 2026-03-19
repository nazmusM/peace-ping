-- Simplified Peace Ping Database Schema (without self_name column)
-- This file contains the essential database structure for Peace Ping application

-- Drop existing tables if they exist (for fresh recreation)
DROP TABLE IF EXISTS sms_inbox;
DROP TABLE IF EXISTS match_tokens;
DROP TABLE IF EXISTS preferences;
DROP TABLE IF EXISTS match_preferences;
DROP TABLE IF EXISTS pings;
DROP TABLE IF EXISTS matches;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS rate_limits;

-- Rate Limits table - for API rate limiting
CREATE TABLE rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash VARCHAR(64) NOT NULL,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    request_count INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_rate_limits_ip_hash (ip_hash),
    INDEX idx_rate_limits_window (window_start),
    INDEX idx_rate_limits_created (created_at),
    UNIQUE KEY uq_rate_limits_ip_hash_window (ip_hash, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users table with verification system
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    contact_encrypted TEXT NOT NULL,
    contact_hash CHAR(64) NOT NULL UNIQUE,
    is_verified BOOLEAN DEFAULT FALSE,
    verification_code VARCHAR(6) NULL,
    verification_expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_users_contact_hash (contact_hash),
    INDEX idx_users_is_verified (is_verified),
    INDEX idx_users_verification_code (verification_code),
    INDEX idx_users_verification_expires (verification_expires_at),
    INDEX idx_users_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Simplified Pings table - stores initial ping attempts (no self_name column)
CREATE TABLE pings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    fingerprint_self CHAR(64) NOT NULL DEFAULT '',
    fingerprint_target CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Unique constraint to prevent duplicate pings
    UNIQUE KEY uq_pings_pair (fingerprint_self, fingerprint_target),
    
    -- Indexes for performance
    INDEX idx_pings_user_id (user_id),
    INDEX idx_pings_fingerprint_self (fingerprint_self),
    INDEX idx_pings_fingerprint_target (fingerprint_target),
    INDEX idx_pings_created_at (created_at),
    
    -- Foreign key constraint
    CONSTRAINT fk_pings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Matches table - stores match information between users
CREATE TABLE matches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fingerprint_a CHAR(64) NOT NULL,
    fingerprint_b CHAR(64) NOT NULL,
    user_a_id BIGINT UNSIGNED NULL,
    user_b_id BIGINT UNSIGNED NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'awaiting_preferences',
    stage INT DEFAULT 1,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY uq_matches_pair (fingerprint_a, fingerprint_b),
    INDEX idx_matches_fingerprint_a (fingerprint_a),
    INDEX idx_matches_fingerprint_b (fingerprint_b),
    INDEX idx_matches_user_a (user_a_id),
    INDEX idx_matches_user_b (user_b_id),
    INDEX idx_matches_status (status),
    INDEX idx_matches_created_at (created_at),
    CONSTRAINT fk_matches_user_a FOREIGN KEY (user_a_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_matches_user_b FOREIGN KEY (user_b_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Match tokens table - stores secure tokens for private preference links
CREATE TABLE match_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    token CHAR(64) NOT NULL UNIQUE,
    is_used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    
    INDEX idx_match_tokens_match_id (match_id),
    INDEX idx_match_tokens_user_id (user_id),
    INDEX idx_match_tokens_token (token),
    INDEX idx_match_tokens_expires (expires_at),
    CONSTRAINT fk_match_tokens_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
    CONSTRAINT fk_match_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preferences table - stores user preferences for matches
CREATE TABLE preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    preference VARCHAR(50) NOT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY uq_preferences_user_match (match_id, user_id),
    INDEX idx_preferences_match_id (match_id),
    INDEX idx_preferences_user_id (user_id),
    CONSTRAINT fk_preferences_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
    CONSTRAINT fk_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create match_preferences alias for compatibility
CREATE TABLE match_preferences AS SELECT * FROM preferences;

-- SMS Inbox table - stores all SMS messages for testing and tracking
CREATE TABLE sms_inbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    phone_number VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    status ENUM('queued', 'sent', 'delivered', 'received', 'failed') DEFAULT 'queued',
    external_id VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_sms_inbox_user_id (user_id),
    INDEX idx_sms_inbox_phone (phone_number),
    INDEX idx_sms_inbox_status (status),
    INDEX idx_sms_inbox_created (created_at),
    CONSTRAINT fk_sms_inbox_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Simplified Peace Ping database schema created successfully!' as message;
