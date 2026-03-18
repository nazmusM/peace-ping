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
        // Validate name
        if (empty(trim($name))) {
            throw new InvalidArgumentException('Name is required.');
        }
        if (strlen($name) > 120) {
            throw new InvalidArgumentException('Name must be less than 120 characters.');
        }
        if (!preg_match('/^[a-zA-Z\s\-\'\.]+$/', $name)) {
            throw new InvalidArgumentException('Name can only contain letters, spaces, hyphens, apostrophes, and periods.');
        }

        // Validate phone
        $normalizedPhone = $this->fingerprint->formatPhone($phone);
        if (!$this->fingerprint->validateIdentifier($normalizedPhone)) {
            throw new InvalidArgumentException('Invalid phone number format. Please use: 07xxx xxxxxx or +447xx xxxxxx');
        }

        // Check if phone already registered
        $existingUser = $this->getUserByContactHash(
            $this->fingerprint->fingerprint($normalizedPhone, $this->pepper)
        );
        if ($existingUser !== null) {
            throw new InvalidArgumentException('This phone number is already registered. Please try logging in.');
        }

        // Generate verification code
        $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store in temporary registration table (or session)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['pending_registration'] = [
            'name' => $name,
            'phone' => $normalizedPhone,
            'verification_code' => $verificationCode,
            'created_at' => time()
        ];

        // Send SMS with verification code
        $this->notificationService->sendVerificationCode($normalizedPhone, $verificationCode);

        return [
            'verification_code' => $verificationCode,
            'message' => 'Verification code sent to your mobile number.'
        ];
    }

    public function verifyAndCreate(string $code): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['pending_registration'])) {
            throw new InvalidArgumentException('No pending registration found.');
        }

        $pending = $_SESSION['pending_registration'];

        // Check if code is expired (10 minutes)
        if (time() - $pending['created_at'] > 600) {
            unset($_SESSION['pending_registration']);
            throw new InvalidArgumentException('Verification code expired. Please register again.');
        }

        if ($pending['verification_code'] !== $code) {
            throw new InvalidArgumentException('Invalid verification code.');
        }

        // Check if user already exists
        $fingerprint = $this->fingerprint->fingerprint($pending['phone'], $this->pepper);
        $existingUser = $this->getUserByContactHash($fingerprint);

        if ($existingUser !== null) {
            // User exists, just log them in
            $_SESSION['user_id'] = $existingUser['id'];
            $_SESSION['user_name'] = $existingUser['name'] ?? $pending['name'];
            unset($_SESSION['pending_registration']);

            return [
                'user_id' => $existingUser['id'],
                'message' => 'Login successful.'
            ];
        }

        // Create new user
        $encryptedPhone = $this->encryption->encrypt($pending['phone']);
        $insert = $this->db->prepare(
            'INSERT INTO users (name, contact_encrypted, contact_hash, created_at) VALUES (?, ?, ?, NOW())'
        );
        $insert->bind_param('sss', $pending['name'], $encryptedPhone, $fingerprint);
        $insert->execute();
        $insert->close();

        $userId = $this->db->insert_id;

        // Log user in
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $pending['name'];
        unset($_SESSION['pending_registration']);

        return [
            'user_id' => $userId,
            'message' => 'Registration successful.'
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
            'name' => $_SESSION['user_name'] ?? ''
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
            'SELECT id, name, contact_encrypted, contact_hash, created_at FROM users WHERE contact_hash = ? LIMIT 1'
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
