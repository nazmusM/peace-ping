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
        private readonly string $pepper,
        private readonly string $portalUrl = ''
    ) {}

    public function submitPing(int $userId, string $targetIdentifier, string $recipientName = ''): array
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

        $this->ensurePingMetadataColumns();
        $targetMasked = $this->maskPhone($normalizedTarget);
        $recipientName = trim($recipientName);
        if (strlen($recipientName) > 120) {
            throw new InvalidArgumentException('Recipient name must be less than 120 characters.');
        }

        $targetMaskIsEmpty = $targetMasked === '';
        error_log(sprintf(
            'PeacePingService::submitPing - params userId=%d fingerprint_self_len=%d fingerprint_target_len=%d target_masked="%s" recipient_name="%s"',
            $userId,
            is_string($userFingerprint) ? strlen($userFingerprint) : 0,
            is_string($fingerprintTarget) ? strlen($fingerprintTarget) : 0,
            $targetMasked,
            $recipientName
        ));
        $recipientNameIsEmpty = $recipientName === '';

        $insert = $this->db->prepare(
            'INSERT INTO pings (user_id, fingerprint_self, fingerprint_target, target_masked, recipient_name, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                 created_at = NOW(),
                 target_masked = IF(?, target_masked, ?),
                 recipient_name = IF(?, recipient_name, ?)'
        );
        $insert->bind_param(
            'issssisss',
            $userId,
            $userFingerprint,
            $fingerprintTarget,
            $targetMasked,
            $recipientName,
            $targetMaskIsEmpty,
            $targetMasked,
            $recipientNameIsEmpty,
            $recipientName
        );
        $ok = $insert->execute();
        if ($ok === false) {
            error_log('PeacePingService::submitPing - insert pings error: ' . $insert->error . ' DB error: ' . $this->db->error);
            $insert->close();
            throw new \RuntimeException('Database error while saving ping.');
        }
        $insert->close();

        $reversePing = $this->db->prepare(
            'SELECT user_id FROM pings
             WHERE fingerprint_self = ? AND fingerprint_target = ? AND user_id != ?
             ORDER BY created_at DESC LIMIT 1'
        );
        $reversePing->bind_param('ssi', $fingerprintTarget, $userFingerprint, $userId);
        $ok = $reversePing->execute();
        if ($ok === false) {
            error_log('PeacePingService::submitPing - reverse select error: ' . $reversePing->error . ' DB error: ' . $this->db->error);
            $reversePing->close();
            throw new \RuntimeException('Database error while checking for reverse ping.');
        }
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
                    'message' => 'Peace Ping matched. Please check your SMS for a private update.'
                ];
            }
        }
        $reversePing->close();

        return [
            'matched' => false,
            'message' => 'Peace Ping sent. If they also enter your number, you will both receive a private update.'
        ];
    }

    public function getPreferenceContext(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT mt.user_id, mt.match_id, mt.is_used, mt.expires_at, m.user_a_id, m.user_b_id, m.status, m.completed_at
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
        $finalMessage = $this->getFinalMessageForMatch((int) $row['match_id']);
        $hasPreference = $this->hasUserPreference((int) $row['match_id'], (int) $row['user_id']);

        return [
            'user_id' => (int) $row['user_id'],
            'match_id' => (int) $row['match_id'],
            'is_used' => (bool) $row['is_used'],
            'is_expired' => $isExpired,
            'is_completed' => $row['completed_at'] !== null || (string) $row['status'] === 'completed',
            'has_preference' => $hasPreference,
            'final_message' => $finalMessage,
            'other_name' => 'the other person',
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
             VALUES (?, ?, ?, NOW(), ?)'
        );
        $stmt->bind_param('iiss', $matchId, $userAId, $tokenA, $expiresAt);
        $stmt->execute();

        $stmt->bind_param('iiss', $matchId, $userBId, $tokenB, $expiresAt);
        $stmt->execute();
        $stmt->close();

        $contactA = $this->userService->getUserContact($userAId);
        $contactB = $this->userService->getUserContact($userBId);

        if ($contactA !== null) {
            $messageA = $this->buildPreferencePromptMessage($this->buildPreferenceUrl($tokenA), $this->buildDashboardUrl());
            $this->notificationService->sendSmsMessage($contactA, $messageA);
        }

        if ($contactB !== null) {
            $messageB = $this->buildPreferencePromptMessage($this->buildPreferenceUrl($tokenB), $this->buildDashboardUrl());
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

        $finalMessage = null;
        if ($preferenceCount === 2) {
            $matchId = (int) $tokenData['match_id'];
            $this->sendStage3Messages($matchId);
            $finalMessage = $this->getFinalMessageForMatch($matchId);

            $this->completeMatch($matchId);
        }

        return [
            'success' => true,
            'final_message' => $finalMessage,
            'message' => $preferenceCount === 2
                ? 'Thank you. Both private preferences are in, and the final update has been sent.'
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

        $message = $this->getFinalMessageForMatch($matchId);
        if ($message === null) {
            return;
        }
        $message .= "\n\nPeace Ping dashboard: " . $this->buildDashboardUrl();

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

    public function getFinalMessageForMatch(int $matchId): ?string
    {
        $preferencesQuery = $this->db->prepare(
            'SELECT preference
             FROM match_preferences
             WHERE match_id = ?
             ORDER BY id ASC'
        );
        $preferencesQuery->bind_param('i', $matchId);
        $preferencesQuery->execute();
        $preferences = $preferencesQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $preferencesQuery->close();

        if (count($preferences) !== 2) {
            return null;
        }

        return $this->determineFinalMessage((string) $preferences[0]['preference'], (string) $preferences[1]['preference']);
    }

    private function hasUserPreference(int $matchId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM match_preferences WHERE match_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $matchId, $userId);
        $stmt->execute();
        $hasPreference = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $hasPreference;
    }

    private function buildPrivateUpdateMessage(string $url): string
    {
        return 'Peace Ping: You have a private update. Please log in to the web portal at ' . $url;
    }

    private function buildPreferencePromptMessage(string $preferenceUrl, string $dashboardUrl): string
    {
        return 'Peace Ping: You have a private preference request. Log in to your dashboard at '
            . $dashboardUrl
            . ' or use this secure link: '
            . $preferenceUrl;
    }

    private function buildPreferenceUrl(string $token): string
    {
        return rtrim($this->getPortalUrl(), '/') . '/preferences?token=' . $token;
    }

    private function getPortalUrl(): string
    {
        if ($this->portalUrl !== '') {
            return $this->portalUrl;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }

    private function buildDashboardUrl(): string
    {
        return rtrim($this->getPortalUrl(), '/') . '/dashboard';
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '') {
            return 'this recipient';
        }

        if (str_starts_with($phone, '+44') && strlen($digits) > 2) {
            $digits = '0' . substr($digits, 2);
        }

        $startLength = str_starts_with($digits, '07') ? 2 : min(3, strlen($digits));
        $start = substr($digits, 0, $startLength);
        $end = substr($digits, -3);

        return $start . '*** ***' . $end;
    }

    private function ensurePingMetadataColumns(): void
    {
        $this->ensureColumn('pings', 'target_masked', "ALTER TABLE pings ADD COLUMN target_masked VARCHAR(40) NULL AFTER fingerprint_target");
        $this->ensureColumn('pings', 'recipient_name', "ALTER TABLE pings ADD COLUMN recipient_name VARCHAR(120) NULL AFTER target_masked");
    }

    private function ensureColumn(string $table, string $column, string $alterSql): void
    {
        $stmt = $this->db->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
        $stmt->bind_param('s', $column);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$exists) {
            $this->db->query($alterSql);
        }
    }

    private function resetMatch(int $matchId): void
    {
        $match = $this->getMatchById($matchId);
        if ($match === null) {
            return;
        }

        $this->db->begin_transaction();

        try {
            $deletePreferences = $this->db->prepare('DELETE FROM match_preferences WHERE match_id = ?');
            $deletePreferences->bind_param('i', $matchId);
            $deletePreferences->execute();
            $deletePreferences->close();

            $deleteTokens = $this->db->prepare('DELETE FROM match_tokens WHERE match_id = ?');
            $deleteTokens->bind_param('i', $matchId);
            $deleteTokens->execute();
            $deleteTokens->close();

            $deleteMatch = $this->db->prepare('DELETE FROM matches WHERE id = ?');
            $deleteMatch->bind_param('i', $matchId);
            $deleteMatch->execute();
            $deleteMatch->close();

            $deletePings = $this->db->prepare(
                'DELETE FROM pings
                 WHERE (fingerprint_self = ? AND fingerprint_target = ?)
                    OR (fingerprint_self = ? AND fingerprint_target = ?)'
            );
            $deletePings->bind_param(
                'ssss',
                $match['fingerprint_a'],
                $match['fingerprint_b'],
                $match['fingerprint_b'],
                $match['fingerprint_a']
            );
            $deletePings->execute();
            $deletePings->close();

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    private function completeMatch(int $matchId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE matches
             SET status = 'completed', stage = 3, completed_at = NOW()
             WHERE id = ?"
        );
        $stmt->bind_param('i', $matchId);
        $stmt->execute();
        $stmt->close();
    }

    private function getMatchById(int $matchId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, fingerprint_a, fingerprint_b FROM matches WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $matchId);
        $stmt->execute();
        $match = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $match ?: null;
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
