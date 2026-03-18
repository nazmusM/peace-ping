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
        private readonly string $pepper
    ) {
    }

    /**
     * Register a new user with encrypted contact information
     */
    public function registerUser(string $contact): array
    {
        $normalizedContact = $this->fingerprint->normalize($contact);
        if (!$this->fingerprint->validateIdentifier($normalizedContact)) {
            throw new InvalidArgumentException('Contact must be a valid email or phone number.');
        }

        // Generate contact hash using HMAC-SHA256
        $contactHash = hash_hmac('sha256', $normalizedContact, $this->pepper);

        // Check if user already exists
        $existingUser = $this->getUserByContactHash($contactHash);
        if ($existingUser !== null) {
            return [
                'user_id' => (int) $existingUser['id'],
                'created' => false,
                'message' => 'User already exists.'
            ];
        }

        // Encrypt contact information
        $encryptedContact = $this->encryption->encrypt($normalizedContact);

        // Insert new user
        $insert = $this->db->prepare(
            'INSERT INTO users (contact_encrypted, contact_hash) VALUES (?, ?)'
        );
        $insert->bind_param('ss', $encryptedContact, $contactHash);
        $insert->execute();
        $userId = (int) $this->db->insert_id;
        $insert->close();

        return [
            'user_id' => $userId,
            'created' => true,
            'message' => 'User registered successfully.'
        ];
    }

    /**
     * Get user by contact hash
     */
    public function getUserByContactHash(string $contactHash): ?array
    {
        $select = $this->db->prepare(
            'SELECT id, contact_encrypted, contact_hash, created_at FROM users WHERE contact_hash = ? LIMIT 1'
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
            'SELECT id, contact_encrypted, contact_hash, created_at FROM users WHERE id = ? LIMIT 1'
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
