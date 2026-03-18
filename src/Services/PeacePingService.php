<?php

namespace App\Services;

use App\Fingerprint;
use App\Services\UserService;
use App\Services\NotificationService;
use InvalidArgumentException;
use mysqli;

/**
 * Multi-stage Peace Ping system
 * 
 * Stage 1: User submits ping (stored as fingerprint)
 * Stage 2: When match detected, send private links via SMS
 * Stage 3: Users submit preferences via private links
 * Stage 4: When both preferences submitted, send final messages
 */
class PeacePingService
{
    public function __construct(
        private readonly mysqli $db,
        private readonly Fingerprint $fingerprint,
        private readonly UserService $userService,
        private readonly NotificationService $notificationService,
        private readonly string $pepper
    ) {}

    /**
     * Stage 1: Submit initial ping
     */
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
            throw new InvalidArgumentException('Invalid phone number format. Please use international format: +[country][number] or local format: [number]');
        }

        $fingerprintTarget = $this->fingerprint->fingerprint($normalizedTarget, $this->pepper);

        // PREVENT SELF-PINGING: Check if user is trying to ping themselves
        if ($userFingerprint === $fingerprintTarget) {
            throw new InvalidArgumentException('You cannot ping yourself. Please enter a different phone number.');
        }

        // PREVENT DUPLICATE PINGS: Check if user already pinged this target recently
        $recentPing = $this->db->prepare(
            'SELECT COUNT(*) as count FROM pings 
             WHERE user_id = ? AND fingerprint_target = ? 
             AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $recentPing->bind_param('is', $userId, $fingerprintTarget);
        $recentPing->execute();
        $pingCount = $recentPing->get_result()->fetch_assoc()['count'];
        $recentPing->close();

        if ($pingCount > 0) {
            throw new InvalidArgumentException('You have already sent a Peace Ping to this number in the last hour. Please wait before trying again.');
        }

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
        $reversePing = $this->db->prepare(
            'SELECT user_id, created_at FROM pings 
             WHERE fingerprint_target = ? AND user_id != ?
             ORDER BY created_at DESC LIMIT 1'
        );
        $reversePing->bind_param('si', $userFingerprint, $userId);
        $reversePing->execute();
        $reverseResult = $reversePing->get_result();

        if ($reverseResult->num_rows > 0) {
            $reverseData = $reverseResult->fetch_assoc();
            $reversePing->close();

            // We have a match! Create match record and send Stage 2 notifications
            $matchId = $this->createMatch($userId, $reverseData['user_id'], $userFingerprint, $fingerprintTarget);

            if ($matchId) {
                $this->sendStage2Notifications($matchId, $userId, $reverseData['user_id']);

                return [
                    'matched' => true,
                    'match_id' => $matchId,
                    'message' => 'Peace Ping matched! Check your SMS for the next steps.'
                ];
            }
        }
        $reversePing->close();

        return [
            'matched' => false,
            'message' => 'Peace Ping sent! If they also send you one, you\'ll both receive SMS messages with questions to help reconnect.'
        ];
    }

    /**
     * Create match record
     */
    private function createMatch(int $userAId, int $userBId, string $fingerprintA, string $fingerprintB): ?int
    {
        // Check if match already exists
        $existingMatch = $this->db->prepare(
            'SELECT id FROM matches 
             WHERE (user_a_id = ? AND user_b_id = ?) OR (user_a_id = ? AND user_b_id = ?)'
        );
        $existingMatch->bind_param('iiii', $userAId, $userBId, $userBId, $userAId);
        $existingMatch->execute();
        $result = $existingMatch->get_result();

        if ($result->num_rows > 0) {
            $matchId = $result->fetch_assoc()['id'];
            $existingMatch->close();
            return $matchId;
        }
        $existingMatch->close();

        // Create new match
        $insert = $this->db->prepare(
            'INSERT INTO matches (user_a_id, user_b_id, fingerprint_a, fingerprint_b, stage, created_at)
             VALUES (?, ?, ?, ?, 2, NOW())'
        );
        $insert->bind_param('iisss', $userAId, $userBId, $fingerprintA, $fingerprintB);

        if ($insert->execute()) {
            $matchId = $insert->insert_id;
            $insert->close();
            return $matchId;
        }

        $insert->close();
        return null;
    }

    /**
     * Send Stage 2 notifications (private links)
     */
    private function sendStage2Notifications(int $matchId, int $userAId, int $userBId): void
    {
        // Generate unique tokens for each user
        $tokenA = $this->generateSecureToken();
        $tokenB = $this->generateSecureToken();

        // Store tokens
        $stmt = $this->db->prepare(
            'INSERT INTO match_tokens (match_id, user_id, token, created_at) VALUES (?, ?, ?, NOW()), (?, ?, ?, NOW())'
        );
        $stmt->bind_param('iisis', $matchId, $userAId, $tokenA, $matchId, $userBId, $tokenB);
        $stmt->execute();
        $stmt->close();

        // Get user contacts
        $userA = $this->userService->getUserById($userAId);
        $userB = $this->userService->getUserById($userBId);

        if ($userA && $userB) {
            $contactA = $this->userService->getUserContact($userAId);
            $contactB = $this->userService->getUserContact($userBId);

            if ($contactA && $contactB) {
                // Send SMS with private links
                $linkA = "https://peaceping.com/preferences/$tokenA";
                $linkB = "https://peaceping.com/preferences/$tokenB";

                $messageA = "🕊️ Peace Ping Match! Someone you're thinking about is also thinking about you. Click here to share your preferences: $linkA";
                $messageB = "🕊️ Peace Ping Match! Someone you're thinking about is also thinking about you. Click here to share your preferences: $linkB";

                $this->notificationService->sendVerificationCode($contactA, $messageA);
                $this->notificationService->sendVerificationCode($contactB, $messageB);
            }
        }
    }

    /**
     * Handle Stage 3: Submit preference via private link
     */
    public function submitPreference(string $token, string $preference): array
    {
        // Validate token
        $tokenQuery = $this->db->prepare(
            'SELECT mt.user_id, mt.match_id, m.stage FROM match_tokens mt 
             JOIN matches m ON mt.match_id = m.id 
             WHERE mt.token = ? AND mt.used = 0'
        );
        $tokenQuery->bind_param('s', $token);
        $tokenQuery->execute();
        $tokenResult = $tokenQuery->get_result();

        if ($tokenResult->num_rows === 0) {
            throw new InvalidArgumentException('Invalid or expired link.');
        }

        $tokenData = $tokenResult->fetch_assoc();
        $tokenQuery->close();

        // Validate preference
        $validPreferences = ['comfortable', 'prefer_other', 'either'];
        if (!in_array($preference, $validPreferences)) {
            throw new InvalidArgumentException('Invalid preference.');
        }

        // Mark token as used
        $stmt = $this->db->prepare('UPDATE match_tokens SET used = 1 WHERE token = ?');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->close();

        // Store preference
        $stmt = $this->db->prepare(
            'INSERT INTO match_preferences (match_id, user_id, preference, created_at) 
             VALUES (?, ?, ?, NOW()) 
             ON DUPLICATE KEY UPDATE preference = ?, created_at = NOW()'
        );
        $stmt->bind_param('iiss', $tokenData['match_id'], $tokenData['user_id'], $preference, $preference);
        $stmt->execute();
        $stmt->close();

        // Check if both users have submitted preferences
        $preferenceCheck = $this->db->prepare(
            'SELECT COUNT(*) as count FROM match_preferences WHERE match_id = ?'
        );
        $preferenceCheck->bind_param('i', $tokenData['match_id']);
        $preferenceCheck->execute();
        $preferenceCount = $preferenceCheck->get_result()->fetch_assoc()['count'];
        $preferenceCheck->close();

        if ($preferenceCount === 2) {
            // Both users have submitted preferences - send Stage 4 messages
            $this->sendStage4Messages($tokenData['match_id']);

            // Update match stage to completed
            $stmt = $this->db->prepare('UPDATE matches SET stage = 4, completed_at = NOW() WHERE id = ?');
            $stmt->bind_param('i', $tokenData['match_id']);
            $stmt->execute();
            $stmt->close();
        }

        return [
            'success' => true,
            'message' => $preferenceCount === 2
                ? 'Thank you! Both preferences received. Check your SMS for the final message.'
                : 'Thank you! We\'ll send the final message once both people have shared their preferences.'
        ];
    }

    /**
     * Send Stage 4 messages (final messages based on preferences)
     */
    private function sendStage4Messages(int $matchId): void
    {
        // Get preferences
        $preferencesQuery = $this->db->prepare(
            'SELECT mp.user_id, mp.preference, u.contact_encrypted FROM match_preferences mp 
             JOIN users u ON mp.user_id = u.id 
             WHERE mp.match_id = ?'
        );
        $preferencesQuery->bind_param('i', $matchId);
        $preferencesQuery->execute();
        $preferences = $preferencesQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $preferencesQuery->close();

        if (count($preferences) !== 2) {
            return;
        }

        // Determine message based on preferences
        $message = $this->determineFinalMessage($preferences[0]['preference'], $preferences[1]['preference']);

        // Send final messages to both users
        foreach ($preferences as $pref) {
            $contact = $this->userService->getUserContact($pref['user_id']);
            if ($contact) {
                $this->notificationService->sendVerificationCode($contact, $message);
            }
        }
    }

    /**
     * Determine final message based on both preferences
     */
    private function determineFinalMessage(string $pref1, string $pref2): string
    {
        // Both comfortable reaching out
        if ($pref1 === 'comfortable' && $pref2 === 'comfortable') {
            return "🎉 Peace Ping Complete! Both of you are comfortable reaching out. Feel free to reconnect when you're ready!";
        }

        // Both prefer the other to reach out
        if ($pref1 === 'prefer_other' && $pref2 === 'prefer_other') {
            return "🕊️ Peace Ping Complete! Both of you prefer the other to reach out. Sometimes the best connections happen when the time is right.";
        }

        // Mixed preferences or either is fine
        if (($pref1 === 'comfortable' && $pref2 === 'prefer_other') ||
            ($pref1 === 'prefer_other' && $pref2 === 'comfortable') ||
            ($pref1 === 'either' || $pref2 === 'either')
        ) {
            return "🌟 Peace Ping Complete! There's mutual interest in reconnecting. One of you is comfortable reaching out, so a beautiful reconnection may be just around the corner!";
        }

        return "💫 Peace Ping Complete! There's mutual interest in reconnecting. The universe has aligned - may your reconnection be peaceful and meaningful.";
    }

    /**
     * Generate secure token for private links
     */
    private function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Get match status for user
     */
    public function getMatchStatus(int $userId): array
    {
        $matches = [];
        $matchQuery = $this->db->prepare("
            SELECT m.*, 
                   CASE 
                       WHEN m.user_a_id = ? THEN 'You initiated'
                       WHEN m.user_b_id = ? THEN 'They initiated'
                       ELSE 'Unknown'
                   END as initiator,
                   m.stage
            FROM matches m
            WHERE m.user_a_id = ? OR m.user_b_id = ?
            ORDER BY m.created_at DESC
        ");
        $matchQuery->bind_param('iiii', $userId, $userId, $userId, $userId);
        $matchQuery->execute();
        $result = $matchQuery->get_result();

        while ($row = $result->fetch_assoc()) {
            $matches[] = $row;
        }
        $matchQuery->close();

        return $matches;
    }
}
