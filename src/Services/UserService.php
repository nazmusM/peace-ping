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
     * Register a new user with encrypted contact information and password
     */
    public function register(string $name, string $phone, string $password): array
    {
        $name = trim($name) !== '' ? trim($name) : 'Peace Ping User';
        if (strlen($name) > 120) {
            throw new InvalidArgumentException('Name must be less than 120 characters.');
        }

        // Validate password
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters long.');
        }

        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new InvalidArgumentException('Password must contain at least one uppercase letter and one number.');
        }

        // Validate phone
        $normalizedPhone = $this->fingerprint->formatPhone($phone);
        if (!$this->fingerprint->validateIdentifier($normalizedPhone)) {
            throw new InvalidArgumentException('Invalid phone number format. Please use international format: +[country][number] or local format: [number]');
        }

        $contactHash = $this->fingerprint->fingerprint($normalizedPhone, $this->pepper);
        $existingUser = $this->getUserByContactHash($contactHash);

        if ($existingUser !== null && (int) $existingUser['is_verified'] === 1) {
            throw new InvalidArgumentException('This mobile number is already registered. Please log in instead.');
        }

        $verificationCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $verificationExpiresAt = date('Y-m-d H:i:s', time() + 600);

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // Encrypt contact info
        $encryptedContact = $this->encryption->encrypt($normalizedPhone);
        if ($existingUser !== null) {
            // Update existing unverified user
            $update = $this->db->prepare("
                UPDATE users 
                SET name = ?, contact_encrypted = ?, password_hash = ?, is_verified = FALSE,
                    verification_code = ?, verification_expires_at = ?
                WHERE id = ?
            ");
            $update->bind_param('sssssi', $name, $encryptedContact, $passwordHash, $verificationCode, $verificationExpiresAt, $existingUser['id']);
            $update->execute();
            $update->close();

            $userId = $existingUser['id'];
        } else {
            // Insert new user with password
            $insert = $this->db->prepare("
                INSERT INTO users (name, contact_encrypted, contact_hash, password_hash, is_verified, verification_code, verification_expires_at)
                VALUES (?, ?, ?, ?, FALSE, ?, ?)
            ");
            $insert->bind_param('ssssss', $name, $encryptedContact, $contactHash, $passwordHash, $verificationCode, $verificationExpiresAt);
            $insert->execute();

            $userId = $insert->insert_id;
            $insert->close();
        }

        $this->notificationService->sendVerificationCode($normalizedPhone, $verificationCode);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['pending_verification_user_id'] = (int) $userId;
        unset($_SESSION['user_id'], $_SESSION['user_name']);

        return [
            'user_id' => $userId,
            'message' => 'Verification code sent to your mobile number.'
        ];
    }

    public function loginWithPassword(string $phone, string $password): array
    {
        $normalizedPhone = $this->fingerprint->formatPhone($phone);
        if (!$this->fingerprint->validateIdentifier($normalizedPhone)) {
            throw new InvalidArgumentException($this->getPhoneFormatGuidance());
        }

        if ($this->isLoginThrottled($phone)) {
            throw new InvalidArgumentException('Too many login attempts. Please try again in 15 minutes.');
        }

        $contactHash = $this->fingerprint->fingerprint($normalizedPhone, $this->pepper);
        $existingUser = $this->getUserByContactHash($contactHash);

        if ($existingUser === null) {
            $this->recordLoginAttempt($phone, false);
            throw new InvalidArgumentException('No account was found for that mobile number. Please register first.');
        }

        if ((int) $existingUser['is_verified'] !== 1) {
            throw new InvalidArgumentException('Your account is not verified. Please register again.');
        }

        $select = $this->db->prepare(
            'SELECT id, name, password_hash FROM users WHERE id = ? LIMIT 1'
        );
        $select->bind_param('i', $existingUser['id']);
        $select->execute();
        $user = $select->get_result()->fetch_assoc();
        $select->close();

        if ($user === null || $user['password_hash'] === null) {
            $this->recordLoginAttempt($phone, false);
            throw new InvalidArgumentException('This account does not have a password set. Please register again to set a password.');
        }

        if (!password_verify($password, $user['password_hash'])) {
            $this->recordLoginAttempt($phone, false);
            throw new InvalidArgumentException('Invalid password. Please try again.');
        }

        $this->recordLoginAttempt($phone, true);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['login_time'] = time();
        unset($_SESSION['pending_verification_user_id']);

        return [
            'success' => true,
            'user_id' => (int) $user['id'],
            'name' => $user['name'],
            'message' => 'Logged in successfully!'
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

        $contact = '';
        try {
            $contact = $this->getUserContact((int) $_SESSION['user_id']) ?? '';
        } catch (\Exception $e) {
            error_log('Error getting user contact: ' . $e->getMessage());
            $contact = '';
        }

        return [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'] ?? '',
            'contact' => $contact
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
        $query = 'SELECT id, name, contact_encrypted, contact_hash, created_at FROM users WHERE id = ? LIMIT 1';
        $select = $this->db->prepare($query);
        if ($select === false) {
            error_log('UserService::getUserById prepare failed: ' . $this->db->error . '. Falling back to direct query.');
            $escapedId = (int) $userId;
            $result = $this->db->query(
                "SELECT id, name, contact_encrypted, contact_hash, created_at FROM users WHERE id = {$escapedId} LIMIT 1"
            );
            if ($result === false) {
                error_log('UserService::getUserById direct query failed: ' . $this->db->error);
                throw new \RuntimeException('Database error fetching user by ID.');
            }
            $user = $result->fetch_assoc();
            $result->close();
            return $user ?: null;
        }

        $select->bind_param('i', $userId);
        if (!$select->execute()) {
            error_log('UserService::getUserById execute failed: ' . $select->error . ' DB error: ' . $this->db->error);
            $select->close();
            throw new \RuntimeException('Database error fetching user by ID.');
        }
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

    public function sendResetOtp(string $phone): array
    {
        $normalizedPhone = $this->fingerprint->formatPhone($phone);
        if (!$this->fingerprint->validateIdentifier($normalizedPhone)) {
            throw new InvalidArgumentException($this->getPhoneFormatGuidance());
        }

        $contactHash = $this->fingerprint->fingerprint($normalizedPhone, $this->pepper);
        $user = $this->getUserByContactHash($contactHash);

        if ($user === null) {
            throw new InvalidArgumentException('No account found for that mobile number.');
        }

        if ((int) $user['is_verified'] !== 1) {
            throw new InvalidArgumentException('This account is not yet verified. Please register first.');
        }

        $this->ensureResetPasswordColumns();

        $stmt = $this->db->prepare('SELECT reset_otp_attempts FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $attempts = (int) ($row['reset_otp_attempts'] ?? 0);
        if ($attempts >= 3) {
            throw new InvalidArgumentException('Too many OTP requests. Please try again later.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + 600);

        $update = $this->db->prepare(
            'UPDATE users SET reset_otp = ?, reset_otp_expires_at = ?, reset_otp_attempts = reset_otp_attempts + 1 WHERE id = ?'
        );
        $update->bind_param('ssi', $code, $expiresAt, $user['id']);
        $update->execute();
        $update->close();

        $this->notificationService->sendVerificationCode($normalizedPhone, $code);

        return [
            'message' => 'A verification code has been sent to your mobile number.',
            'user_id' => (int) $user['id'],
        ];
    }

    public function verifyResetOtp(string $phone, string $code): array
    {
        $normalizedPhone = $this->fingerprint->formatPhone($phone);
        $contactHash = $this->fingerprint->fingerprint($normalizedPhone, $this->pepper);
        $user = $this->getUserByContactHash($contactHash);

        if ($user === null) {
            throw new InvalidArgumentException('No account found for that mobile number.');
        }

        $this->ensureResetPasswordColumns();

        $stmt = $this->db->prepare(
            'SELECT reset_otp, reset_otp_expires_at FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row === null || $row['reset_otp'] === null) {
            throw new InvalidArgumentException('No verification code has been sent. Please request one first.');
        }

        if (strtotime((string) $row['reset_otp_expires_at']) < time()) {
            throw new InvalidArgumentException('Verification code has expired. Please request a new one.');
        }

        if ((string) $row['reset_otp'] !== $code) {
            throw new InvalidArgumentException('Invalid verification code. Please try again.');
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['reset_verified_user_id'] = (int) $user['id'];

        $clear = $this->db->prepare('UPDATE users SET reset_otp = NULL, reset_otp_expires_at = NULL WHERE id = ?');
        $clear->bind_param('i', $user['id']);
        $clear->execute();
        $clear->close();

        return [
            'success' => true,
            'message' => 'Verified successfully. You can now reset your password.',
        ];
    }

    public function resetPassword(string $phone, string $password, string $confirmPassword): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $verifiedUserId = $_SESSION['reset_verified_user_id'] ?? null;
        if ($verifiedUserId === null) {
            throw new InvalidArgumentException('Session expired. Please verify your identity again.');
        }

        $normalizedPhone = $this->fingerprint->formatPhone($phone);
        $contactHash = $this->fingerprint->fingerprint($normalizedPhone, $this->pepper);
        $user = $this->getUserByContactHash($contactHash);

        if ($user === null || (int) $user['id'] !== $verifiedUserId) {
            unset($_SESSION['reset_verified_user_id']);
            throw new InvalidArgumentException('Session mismatch. Please start the process again.');
        }

        if ($password === '') {
            throw new InvalidArgumentException('Password is required.');
        }

        if ($confirmPassword === '') {
            throw new InvalidArgumentException('Please confirm your password.');
        }

        if ($password !== $confirmPassword) {
            throw new InvalidArgumentException('The passwords do not match.');
        }

        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters long.');
        }

        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new InvalidArgumentException('Password must contain at least one uppercase letter and one number.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $update = $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $update->bind_param('si', $passwordHash, $verifiedUserId);
        $update->execute();
        $update->close();

        unset($_SESSION['reset_verified_user_id']);

        return [
            'success' => true,
            'message' => 'Password has been reset successfully. You can now log in with your new password.',
        ];
    }

    public function isLoginThrottled(string $phone): bool
    {
        $normalizedPhone = $this->fingerprint->formatPhone($phone);
        $contactHash = $this->fingerprint->fingerprint($normalizedPhone, $this->pepper);

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS count FROM login_attempts
             WHERE contact_hash = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $stmt->bind_param('s', $contactHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['count'] ?? 0) >= 5;
    }

    public function getLoginLockoutRemaining(string $phone): int
    {
        $normalizedPhone = $this->fingerprint->formatPhone($phone);
        $contactHash = $this->fingerprint->fingerprint($normalizedPhone, $this->pepper);

        $stmt = $this->db->prepare(
            'SELECT UNIX_TIMESTAMP(attempted_at) AS attempted_ts FROM login_attempts
             WHERE contact_hash = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
             ORDER BY attempted_at DESC
             LIMIT 1 OFFSET 4'
        );
        $stmt->bind_param('s', $contactHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return 0;
        }

        $lockoutEnd = ((int) $row['attempted_ts']) + 900;
        return max(0, $lockoutEnd - time());
    }

    public function recordLoginAttempt(string $phone, bool $success): void
    {
        $normalizedPhone = $this->fingerprint->formatPhone($phone);
        $contactHash = $this->fingerprint->fingerprint($normalizedPhone, $this->pepper);

        if ($success) {
            $delete = $this->db->prepare('DELETE FROM login_attempts WHERE contact_hash = ?');
            $delete->bind_param('s', $contactHash);
            $delete->execute();
            $delete->close();
        } else {
            $insert = $this->db->prepare(
                'INSERT INTO login_attempts (contact_hash, attempted_at) VALUES (?, NOW())'
            );
            $insert->bind_param('s', $contactHash);
            $insert->execute();
            $insert->close();
        }
    }

    private function ensureResetPasswordColumns(): void
    {
        $this->ensureColumn('users', 'reset_otp', "ALTER TABLE users ADD COLUMN reset_otp VARCHAR(6) NULL AFTER verification_expires_at");
        $this->ensureColumn('users', 'reset_otp_expires_at', "ALTER TABLE users ADD COLUMN reset_otp_expires_at TIMESTAMP NULL AFTER reset_otp");
        $this->ensureColumn('users', 'reset_otp_attempts', "ALTER TABLE users ADD COLUMN reset_otp_attempts INT NOT NULL DEFAULT 0 AFTER reset_otp_expires_at");
    }

    private function ensureColumn(string $table, string $column, string $alterSql): void
    {
        $columnEscaped = $this->db->real_escape_string($column);
        $dbName = $this->db->query('SELECT DATABASE() AS db')->fetch_assoc()['db'] ?? '';
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->bind_param('sss', $dbName, $table, $columnEscaped);
        $stmt->execute();
        $exists = ((int) $stmt->get_result()->fetch_assoc()['cnt']) > 0;
        $stmt->close();

        if (!$exists) {
            $this->db->query($alterSql);
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
