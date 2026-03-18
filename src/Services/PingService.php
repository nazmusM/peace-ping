<?php

namespace App\Services;

use App\Fingerprint;
use App\Services\UserService;
use InvalidArgumentException;
use mysqli;

class PingService
{
    public function __construct(
        private readonly mysqli $db,
        private readonly Fingerprint $fingerprint,
        private readonly UserService $userService,
        private readonly MatchService $matchService,
        private readonly NotificationService $notificationService,
        private readonly string $pepper
    ) {}

    public function submitPing(int $userId, string $targetIdentifier): array
    {
        // Get user contact
        $userContact = $this->userService->getUserContact($userId);
        if ($userContact === null) {
            throw new InvalidArgumentException('User contact not found.');
        }

        $userFingerprint = $this->fingerprint->fingerprint($userContact, $this->pepper);

        // Validate target identifier
        $normalizedTarget = $this->fingerprint->normalize($targetIdentifier);
        if (!$this->fingerprint->validateIdentifier($normalizedTarget)) {
            throw new InvalidArgumentException('Invalid target identifier format.');
        }

        $fingerprintTarget = $this->fingerprint->fingerprint($normalizedTarget, $this->pepper);

        // Insert the ping
        $insert = $this->db->prepare(
            'INSERT INTO pings (user_id, fingerprint_target, created_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE created_at = NOW()'
        );
        $insert->bind_param('is', $userId, $fingerprintTarget);
        $insert->execute();
        $insert->close();

        // Check for reverse ping (match detection)
        $reverse = $this->db->prepare(
            'SELECT p.user_id, u.contact_encrypted FROM pings p 
             JOIN users u ON p.user_id = u.id
             WHERE p.fingerprint_target = ? AND p.user_id != ? LIMIT 1'
        );
        $reverse->bind_param('si', $userFingerprint, $userId);
        $reverse->execute();
        $reverseFound = $reverse->get_result()->fetch_assoc();
        $reverse->close();

        if ($reverseFound === null) {
            return [
                'accepted' => true,
                'matched' => false,
                'message' => 'Ping recorded. If they also ping you, you\'ll both get notified!'
            ];
        }

        // Create match with user IDs
        $matchResult = $this->matchService->createOrGetMatch(
            $userFingerprint,
            $fingerprintTarget,
            $userId,
            (int) $reverseFound['user_id']
        );

        if ($matchResult['created'] === true) {
            // Get contact information for notifications
            $userContact = $this->userService->getUserContact($userId);
            $targetUserId = (int) $reverseFound['user_id'];
            $targetContact = $this->userService->getUserContact($targetUserId);

            // Send SMS flow-chart questions immediately when match is found
            if ($userContact && $targetContact) {
                // Send first question to both users
                $question1 = "How would you prefer to reconnect?";

                $this->notificationService->sendPeacePingQuestion($targetContact, 1, $question1);
                $this->notificationService->sendPeacePingQuestion($userContact, 1, $question1);
            }

            // Mark match as resolved since we're sending questions immediately
            $this->matchService->markResolved($matchResult['id']);
        }

        return [
            'accepted' => true,
            'matched' => true,
            'message' => 'Mutual match detected! Both of you will receive SMS notifications.',
            'contacts' => [
                'your_contact' => $userContact,
                'other_contact' => $targetContact
            ]
        ];
    }

    public function submitPingLegacy(string $selfName, string $selfIdentifier, string $targetIdentifier): array
    {
        // Legacy method for backward compatibility
        $normalizedName = $this->normalizeName($selfName);
        if ($normalizedName === '') {
            throw new InvalidArgumentException('Your name is required.');
        }

        $fingerprintSelf = $this->fingerprint->fingerprint($selfIdentifier, $this->pepper);
        $fingerprintTarget = $this->fingerprint->fingerprint($targetIdentifier, $this->pepper);

        if ($fingerprintSelf === $fingerprintTarget) {
            throw new InvalidArgumentException('Self and target identifiers must be different.');
        }

        $insert = $this->db->prepare(
            'INSERT INTO pings (user_id, self_name, fingerprint_self, fingerprint_target, created_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE self_name = VALUES(self_name), created_at = NOW()'
        );
        $nullUserId = null;
        $insert->bind_param('isss', $nullUserId, $normalizedName, $fingerprintSelf, $fingerprintTarget);
        $insert->execute();
        $insert->close();

        $reverse = $this->db->prepare(
            'SELECT id, self_name FROM pings WHERE fingerprint_self = ? AND fingerprint_target = ? LIMIT 1'
        );
        $reverse->bind_param('ss', $fingerprintTarget, $fingerprintSelf);
        $reverse->execute();
        $reverseFound = $reverse->get_result()->fetch_assoc();
        $reverse->close();

        if ($reverseFound === null) {
            return [
                'accepted' => true,
                'matched' => false,
                'message' => 'Ping recorded.',
            ];
        }

        $matchResult = $this->matchService->createOrGetMatch($fingerprintSelf, $fingerprintTarget);
        if ($matchResult['created'] === true) {
            $otherNameForSelf = isset($reverseFound['self_name']) ? $this->normalizeName((string) $reverseFound['self_name']) : '';
            if ($otherNameForSelf === '') {
                $otherNameForSelf = 'the other person';
            }
            // For legacy method, just send a simple notification
            $this->notificationService->sendPreferencePrompt(
                $fingerprintSelf,
                $fingerprintTarget,
                [$selfIdentifier, $targetIdentifier]
            );
        }

        return [
            'accepted' => true,
            'matched' => true,
            'message' => 'Mutual openness detected.',
        ];
    }

    private function normalizeName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return '';
        }

        if (strlen($trimmed) > 120) {
            $trimmed = substr($trimmed, 0, 120);
        }

        return preg_replace('/\s+/', ' ', $trimmed) ?? '';
    }
}
