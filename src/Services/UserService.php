<?php

namespace App\Services;

use App\Fingerprint;
use App\Utils\Encryption;
use InvalidArgumentException;
use mysqli;

class UserService
{
    public function __construct(
        private readonly mysqli $db,
        private readonly Fingerprint $fingerprint,
        private readonly Encryption $encryption,
        private readonly NotificationService $notificationService,
        private readonly string $pepper
    ) {}

    /**
     * Register a new user with encrypted contact information
     */
    public function register(string $name, string $phone): array
    {
        $name = trim($name) !== '' ? trim($name) : 'Peace Ping User';
        if (strlen($name) > 120) {
            throw new InvalidArgumentException('Name must be less than 120 characters.');
        }

        // Validate phone
        $normalizedPhone = $this->fingerprint->formatPhone($phone);
        if (!$this->fingerprint->validateIdentifier($normalizedPhone)) {
            throw new InvalidArgumentException('Invalid phone number format. Please use international format: +[country][number] or local format: [number]');
        }

        $existingUser = $this->getUserByContactHash(
            $this->fingerprint->fingerprint($normalizedPhone, $this->pepper)
        );

        // Generate verification code
        $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $verificationExpiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

        // Encrypt contact info
        $encryptedContact = $this->encryption->encrypt($normalizedPhone);
        $contactHash = $this->fingerprint->fingerprint($normalizedPhone, $this->pepper);

        if ($existingUser !== null) {
            // Update existing unverified user
            $update = $this->db->prepare("
                UPDATE users 
                SET name = ?, contact_encrypted = ?, verification_code = ?, verification_expires_at = ?
                WHERE id = ?
            ");
            $update->bind_param('ssssi', $name, $encryptedContact, $verificationCode, $verificationExpiresAt, $existingUser['id']);
            $update->execute();
            $update->close();

            $userId = $existingUser['id'];
        } else {
            // Insert new user with verification info
            $insert = $this->db->prepare("
                INSERT INTO users (name, contact_encrypted, contact_hash, is_verified, verification_code, verification_expires_at)
                VALUES (?, ?, ?, FALSE, ?, ?)
            ");
            $insert->bind_param('sssss', $name, $encryptedContact, $contactHash, $verificationCode, $verificationExpiresAt);
            $insert->execute();

            $userId = $insert->insert_id;
            $insert->close();
        }

        // Send SMS with verification code
        $this->notificationService->sendVerificationCode($normalizedPhone, $verificationCode);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['pending_verification_user_id'] = (int) $userId;

        return [
            'user_id' => $userId,
            'message' => 'Verification code sent to your mobile number.'
        ];
    }

    public function normalizePhone(string $phone): string
    {
        return $this->fingerprint->formatPhone($phone);
    }

    public function getPhoneFormatGuidance(): string
    {
        return $this->fingerprint->getFormatGuidance();
    }

    public function verifyAndCreate(string $code, ?int $pendingUserId = null): array
    {
        if ($pendingUserId !== null) {
            $query = $this->db->prepare("
                SELECT id, name, contact_encrypted, verification_expires_at
                FROM users
                WHERE id = ? AND verification_code = ?
                LIMIT 1
            ");
            $query->bind_param('is', $pendingUserId, $code);
        } else {
            $query = $this->db->prepare("
                SELECT id, name, contact_encrypted, verification_expires_at
                FROM users
                WHERE verification_code = ?
                LIMIT 1
            ");
            $query->bind_param('s', $code);
        }
        $query->execute();
        $result = $query->get_result();

        if ($result->num_rows === 0) {
            throw new InvalidArgumentException('Invalid or expired verification code.');
        }

        $user = $result->fetch_assoc();
        $query->close();

        // Check if code is expired
        if (strtotime($user['verification_expires_at']) < time()) {
            // Clear expired verification code
            $update = $this->db->prepare("UPDATE users SET verification_code = NULL, verification_expires_at = NULL WHERE id = ?");
            $update->bind_param('i', $user['id']);
            $update->execute();
            $update->close();

            throw new InvalidArgumentException('Verification code expired. Please register again.');
        }

        // Mark user as verified and clear verification fields
        $update = $this->db->prepare("
            UPDATE users 
            SET is_verified = TRUE, verification_code = NULL, verification_expires_at = NULL 
            WHERE id = ?
        ");
        $update->bind_param('i', $user['id']);
        $update->execute();
        $update->close();

        // Start session and log user in
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        unset($_SESSION['pending_verification_user_id']);

        return [
            'success' => true,
            'user_id' => $user['id'],
            'name' => $user['name'],
            'message' => 'Account verified successfully! You are now logged in.'
        ];
    }

    public function isLoggedIn(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['user_id']);
    }

    public function getCurrentUser(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        return [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'] ?? '',
            'contact' => $this->getUserContact((int) $_SESSION['user_id']) ?? ''
        ];
    }

    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();
    }

    /**
     * Get user by contact hash
     */
    public function getUserByContactHash(string $contactHash): ?array
    {
        $select = $this->db->prepare(
            'SELECT id, name, contact_encrypted, contact_hash, is_verified, created_at FROM users WHERE contact_hash = ? LIMIT 1'
        );
        $select->bind_param('s', $contactHash);
        $select->execute();
        $user = $select->get_result()->fetch_assoc();
        $select->close();

        return $user ?: null;
    }

    /**
     * Get user by ID
     */
    public function getUserById(int $userId): ?array
    {
        $select = $this->db->prepare(
            'SELECT id, name, contact_encrypted, contact_hash, created_at FROM users WHERE id = ? LIMIT 1'
        );
        $select->bind_param('i', $userId);
        $select->execute();
        $user = $select->get_result()->fetch_assoc();
        $select->close();

        return $user ?: null;
    }

    /**
     * Get decrypted contact information for a user
     */
    public function getUserContact(int $userId): ?string
    {
        $user = $this->getUserById($userId);
        if ($user === null) {
            return null;
        }

        try {
            return $this->encryption->decrypt($user['contact_encrypted']);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Find user by fingerprint (target identifier)
     */
    public function findUserByFingerprint(string $fingerprint): ?array
    {
        $select = $this->db->prepare(
            'SELECT id, contact_encrypted, contact_hash, created_at FROM users WHERE contact_hash = ? LIMIT 1'
        );
        $select->bind_param('s', $fingerprint);
        $select->execute();
        $user = $select->get_result()->fetch_assoc();
        $select->close();

        return $user ?: null;
    }

    /**
     * Get both users' contact information for notification
     */
    public function getMatchedUsersContacts(int $userAId, int $userBId): array
    {
        $contactA = $this->getUserContact($userAId);
        $contactB = $this->getUserContact($userBId);

        return [
            'user_a_contact' => $contactA,
            'user_b_contact' => $contactB
        ];
    }
}
