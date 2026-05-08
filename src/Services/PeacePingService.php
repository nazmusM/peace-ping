<?php

namespace App\Services;

use App\Fingerprint;
use InvalidArgumentException;
use mysqli;

class PeacePingService
{
    public function __construct(
        private readonly mysqli $db,
        private readonly Fingerprint $fingerprint,
        private readonly UserService $userService,
        private readonly NotificationService $notificationService,
        private readonly string $pepper
    ) {}

    public function submitPing(int $userId, string $targetIdentifier): array
    {

        $userContact = $this->userService->getUserContact($userId);
        if ($userContact === null) {
            throw new InvalidArgumentException('User contact not found.');
        }

        $userFingerprint = $this->fingerprint->fingerprint($userContact, $this->pepper);
        $normalizedTarget = $this->fingerprint->normalize($targetIdentifier);
        if (!$this->fingerprint->validateIdentifier($normalizedTarget)) {
            throw new InvalidArgumentException('Invalid phone number format. Please use international format: +[country][number] or local format: [number]');
        }

        $fingerprintTarget = $this->fingerprint->fingerprint($normalizedTarget, $this->pepper);
        if ($userFingerprint === $fingerprintTarget) {
            throw new InvalidArgumentException('You cannot ping yourself. Please enter a different phone number.');
        }

        $insert = $this->db->prepare(
            'INSERT INTO pings (user_id, fingerprint_self, fingerprint_target, created_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE created_at = NOW()'
        );
        $insert->bind_param('iss', $userId, $userFingerprint, $fingerprintTarget);
        $insert->execute();
        $insert->close();

        $reversePing = $this->db->prepare(
            'SELECT user_id FROM pings
             WHERE fingerprint_target = ? AND user_id != ?
             ORDER BY created_at DESC LIMIT 1'
        );
        $reversePing->bind_param('si', $userFingerprint, $userId);
        $reversePing->execute();
        $reverseResult = $reversePing->get_result();

        if ($reverseResult->num_rows > 0) {
            $reverseData = $reverseResult->fetch_assoc();
            $reversePing->close();

            $matchId = $this->createMatch($userId, (int) $reverseData['user_id'], $userFingerprint, $fingerprintTarget);
            if ($matchId) {
                $this->sendStage2Notifications($matchId, $userId, (int) $reverseData['user_id']);

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
            'message' => 'Peace Ping sent! If they also send you one, you\'ll both receive SMS messages with secure links to share your reconnection preferences.'
        ];
    }

    public function getPreferenceContext(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT mt.user_id, mt.match_id, mt.is_used, mt.expires_at, m.user_a_id, m.user_b_id
             FROM match_tokens mt
             JOIN matches m ON mt.match_id = m.id
             WHERE mt.token = ?
             LIMIT 1'
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        $isExpired = strtotime((string) $row['expires_at']) <= time();
        $otherUserId = ((int) $row['user_a_id'] === (int) $row['user_id']) ? (int) $row['user_b_id'] : (int) $row['user_a_id'];
        $otherUser = $otherUserId > 0 ? $this->userService->getUserById($otherUserId) : null;

        return [
            'user_id' => (int) $row['user_id'],
            'match_id' => (int) $row['match_id'],
            'is_used' => (bool) $row['is_used'],
            'is_expired' => $isExpired,
            'other_name' => $this->displayName($otherUser['name'] ?? null),
        ];
    }

    private function createMatch(int $userAId, int $userBId, string $fingerprintA, string $fingerprintB): ?int
    {
        $existingMatch = $this->db->prepare(
            'SELECT id FROM matches
             WHERE (user_a_id = ? AND user_b_id = ?) OR (user_a_id = ? AND user_b_id = ?)'
        );
        $existingMatch->bind_param('iiii', $userAId, $userBId, $userBId, $userAId);
        $existingMatch->execute();
        $result = $existingMatch->get_result();

        if ($result->num_rows > 0) {
            $matchId = (int) $result->fetch_assoc()['id'];
            $existingMatch->close();
            return $matchId;
        }
        $existingMatch->close();

        $insert = $this->db->prepare(
            'INSERT INTO matches (user_a_id, user_b_id, fingerprint_a, fingerprint_b, stage, created_at)
             VALUES (?, ?, ?, ?, 2, NOW())'
        );
        $insert->bind_param('iiss', $userAId, $userBId, $fingerprintA, $fingerprintB);

        if ($insert->execute()) {
            $matchId = $insert->insert_id;
            $insert->close();
            return $matchId;
        }

        $insert->close();
        return null;
    }

    private function sendStage2Notifications(int $matchId, int $userAId, int $userBId): void
    {
        $tokenA = bin2hex(random_bytes(32));
        $tokenB = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 7 * 24 * 60 * 60);

        $stmt = $this->db->prepare(
            'INSERT INTO match_tokens (match_id, user_id, token, created_at, expires_at)
             VALUES (?, ?, ?, NOW(), ?), (?, ?, ?, NOW(), ?)'
        );
        $stmt->bind_param('iissiiss', $matchId, $userAId, $tokenA, $expiresAt, $matchId, $userBId, $tokenB, $expiresAt);
        $stmt->execute();
        $stmt->close();

        $userA = $this->userService->getUserById($userAId);
        $userB = $this->userService->getUserById($userBId);
        $contactA = $this->userService->getUserContact($userAId);
        $contactB = $this->userService->getUserContact($userBId);

        if ($contactA !== null) {
            $messageA = $this->buildStage2Message($this->displayName($userB['name'] ?? null), $this->buildPreferenceUrl($tokenA));
            $this->notificationService->sendSmsMessage($contactA, $messageA);
        }

        if ($contactB !== null) {
            $messageB = $this->buildStage2Message($this->displayName($userA['name'] ?? null), $this->buildPreferenceUrl($tokenB));
            $this->notificationService->sendSmsMessage($contactB, $messageB);
        }
    }

    public function submitPreference(string $token, string $preference): array
    {
        $tokenQuery = $this->db->prepare(
            'SELECT mt.user_id, mt.match_id
             FROM match_tokens mt
             WHERE mt.token = ? AND mt.is_used = 0 AND mt.expires_at > NOW()'
        );
        $tokenQuery->bind_param('s', $token);
        $tokenQuery->execute();
        $tokenResult = $tokenQuery->get_result();

        if ($tokenResult->num_rows === 0) {
            throw new InvalidArgumentException('Invalid or expired link.');
        }

        $tokenData = $tokenResult->fetch_assoc();
        $tokenQuery->close();

        $validPreferences = ['comfortable', 'prefer_other', 'either'];
        if (!in_array($preference, $validPreferences, true)) {
            throw new InvalidArgumentException('Invalid preference.');
        }

        $stmt = $this->db->prepare('UPDATE match_tokens SET is_used = 1 WHERE token = ?');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare(
            'INSERT INTO match_preferences (match_id, user_id, preference, submitted_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE preference = VALUES(preference), submitted_at = NOW()'
        );
        $stmt->bind_param('iis', $tokenData['match_id'], $tokenData['user_id'], $preference);
        $stmt->execute();
        $stmt->close();

        $preferenceCheck = $this->db->prepare(
            'SELECT COUNT(*) as count FROM match_preferences WHERE match_id = ?'
        );
        $preferenceCheck->bind_param('i', $tokenData['match_id']);
        $preferenceCheck->execute();
        $preferenceCount = (int) $preferenceCheck->get_result()->fetch_assoc()['count'];
        $preferenceCheck->close();

        if ($preferenceCount === 2) {
            $this->sendStage3Messages((int) $tokenData['match_id']);

            $stmt = $this->db->prepare('UPDATE matches SET stage = 4, completed_at = NOW() WHERE id = ?');
            $stmt->bind_param('i', $tokenData['match_id']);
            $stmt->execute();
            $stmt->close();
        }

        return [
            'success' => true,
            'message' => $preferenceCount === 2
                ? 'Thank you. Both private preferences are in, and the final message has been sent.'
                : 'Thank you. Your private preference has been recorded. We will send the final message once both people have responded.'
        ];
    }

    private function sendStage3Messages(int $matchId): void
    {
        $preferencesQuery = $this->db->prepare(
            'SELECT mp.user_id, mp.preference
             FROM match_preferences mp
             WHERE mp.match_id = ?
             ORDER BY mp.id ASC'
        );
        $preferencesQuery->bind_param('i', $matchId);
        $preferencesQuery->execute();
        $preferences = $preferencesQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $preferencesQuery->close();

        if (count($preferences) !== 2) {
            return;
        }

        $message = $this->determineFinalMessage($preferences[0]['preference'], $preferences[1]['preference']);

        foreach ($preferences as $pref) {
            $userId = (int) $pref['user_id'];
            $contact = $this->userService->getUserContact($userId);
            if ($contact !== null) {
                $this->notificationService->sendSmsMessage($contact, $message);
            }
        }
    }

    private function determineFinalMessage(string $pref1, string $pref2): string
    {
        if ($pref1 === 'comfortable' || $pref2 === 'comfortable') {
            return "You're both open to reconnecting.\nEither of you may reach out in whatever way feels right.";
        }

        if ($pref1 === 'either' && $pref2 === 'either') {
            return "There is mutual openness to reconnecting.\nIf contact happens, it can be assumed to be welcome.";
        }

        if ($pref1 === 'prefer_other' && $pref2 === 'prefer_other') {
            return "Mutual openness has been confirmed.\nNo one is expected to initiate. Contact may happen naturally, or not at all.";
        }

        return "There is mutual openness to reconnecting.\nIf contact happens, it can be assumed to be welcome.";
    }

    private function buildStage2Message(string $otherName, string $link): string
    {
        return "You and {$otherName} have both indicated openness to reconnecting.\nHow would you like this to proceed?\n\nVisit preference page: {$link}";
    }

    private function buildPreferenceUrl(string $token): string
    {
        return rtrim($this->getBaseUrl(), '/') . '/preferences?token=' . $token;
    }

    private function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }

    private function displayName(?string $name): string
    {
        $name = trim((string) $name);
        return $name !== '' ? $name : 'the other person';
    }

    public function getMatchStatus(int $userId): array
    {
        $matches = [];
        $matchQuery = $this->db->prepare("\n            SELECT m.*,\n                   CASE\n                       WHEN m.user_a_id = ? THEN 'You initiated'\n                       WHEN m.user_b_id = ? THEN 'They initiated'\n                       ELSE 'Unknown'\n                   END as initiator,\n                   m.stage\n            FROM matches m\n            WHERE m.user_a_id = ? OR m.user_b_id = ?\n            ORDER BY m.created_at DESC\n        ");
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
