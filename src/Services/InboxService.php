<?php

namespace App\Services;

use App\Database\Database;

/**
 * Simple Inbox Service - No SMS dependencies
 * Just logs messages directly to database
 */
class InboxService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Log a message directly to inbox
     */
    public function logMessage(?int $userId, string $phoneNumber, string $message, string $direction = 'outbound'): int
    {
        $query = $this->db->prepare("
            INSERT INTO sms_inbox (user_id, phone_number, message, direction, status)
            VALUES (?, ?, ?, ?, 'sent')
        ");

        $query->bind_param('isss', $userId, $phoneNumber, $message, $direction);
        $query->execute();

        return $this->db->insert_id;
    }

    /**
     * Log verification code for testing
     */
    public function logVerificationCode(?int $userId, string $phoneNumber, string $code): void
    {
        $message = "🔐 Your Peace Ping verification code is: $code\n\nThis code will expire in 10 minutes. Please do not share this code with anyone.";
        $this->logMessage($userId, $phoneNumber, $message, 'outbound');
    }

    /**
     * Log match notification
     */
    public function logMatchNotification(?int $userId, string $phoneNumber, string $token): void
    {
        $link = "/preferences?token=$token";
        $message = "🕊️ Peace Ping Match! Someone you're thinking about is also thinking about you. Click here to share your preferences: $link";

        // Debug: Log to error file
        error_log("DEBUG: logMatchNotification called - userId: $userId, phone: $phoneNumber, token: $token");

        $result = $this->logMessage($userId, $phoneNumber, $message, 'outbound');
        error_log("DEBUG: logMessage result: $result");
    }

    /**
     * Get user's messages
     */
    public function getUserMessages(int $userId, int $limit = 50): array
    {
        $query = $this->db->prepare("
            SELECT id, phone_number, message, direction, status, external_id, created_at
            FROM sms_inbox 
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");

        $query->bind_param('ii', $userId, $limit);
        $query->execute();

        $messages = [];
        $result = $query->get_result();

        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }

        return $messages;
    }

    /**
     * Get all recent messages (for testing) - includes messages without user_id
     */
    public function getRecentMessages(int $limit = 20): array
    {
        $query = $this->db->prepare("
            SELECT phone_number, message, direction, status, external_id, created_at
            FROM sms_inbox 
            ORDER BY created_at DESC
            LIMIT ?
        ");

        $query->bind_param('i', $limit);
        $query->execute();

        $messages = [];
        $result = $query->get_result();

        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }

        return $messages;
    }

    /**
     * Get all messages without user restrictions (for public testing)
     */
    public function getAllMessages(int $limit = 50): array
    {
        $query = $this->db->prepare("
            SELECT phone_number, message, direction, status, external_id, created_at
            FROM sms_inbox 
            ORDER BY created_at DESC
            LIMIT ?
        ");

        $query->bind_param('i', $limit);
        $query->execute();

        $messages = [];
        $result = $query->get_result();

        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }

        return $messages;
    }
}
