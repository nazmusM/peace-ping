<?php

namespace App\Services;

use App\Fingerprint;
use InvalidArgumentException;
use mysqli;

class PreferenceService
{
    private const VALID_PREFERENCES = ['reach_out', 'prefer_other', 'either'];

    public function __construct(
        private readonly mysqli $db,
        private readonly Fingerprint $fingerprint,
        private readonly MatchService $matchService,
        private readonly NotificationService $notificationService,
        private readonly string $pepper
    ) {
    }

    public function submitPreference(string $selfIdentifier, string $targetIdentifier, string $preference): array
    {
        $normalizedPreference = trim(strtolower($preference));
        if (!in_array($normalizedPreference, self::VALID_PREFERENCES, true)) {
            throw new InvalidArgumentException('Invalid preference value.');
        }

        $selfFingerprint = $this->fingerprint->fingerprint($selfIdentifier, $this->pepper);
        $targetFingerprint = $this->fingerprint->fingerprint($targetIdentifier, $this->pepper);
        if ($selfFingerprint === $targetFingerprint) {
            throw new InvalidArgumentException('Self and target identifiers must be different.');
        }

        $match = $this->matchService->getMatchByFingerprints($selfFingerprint, $targetFingerprint);
        if ($match === null) {
            throw new InvalidArgumentException('No mutual match found yet.');
        }
        if ((string) $match['status'] === 'resolved') {
            return [
                'resolved' => true,
                'message' => 'Mutual openness has already been confirmed for this match.',
            ];
        }
        $matchId = (int) $match['id'];

        $upsert = $this->db->prepare(
            "INSERT INTO preferences (match_id, fingerprint, preference, created_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE preference = VALUES(preference), created_at = NOW()"
        );
        $upsert->bind_param('iss', $matchId, $selfFingerprint, $normalizedPreference);
        $upsert->execute();
        $upsert->close();

        $select = $this->db->prepare(
            'SELECT fingerprint, preference FROM preferences WHERE match_id = ? ORDER BY id ASC'
        );
        $select->bind_param('i', $matchId);
        $select->execute();
        $result = $select->get_result();
        $preferences = [];
        while ($row = $result->fetch_assoc()) {
            $preferences[] = $row;
        }
        $select->close();

        if (count($preferences) < 2) {
            return [
                'resolved' => false,
                'message' => 'Preference recorded. Waiting for the other person.',
            ];
        }

        $message = $this->buildResolutionMessage($preferences[0]['preference'], $preferences[1]['preference']);
        $this->notificationService->sendFinalPermissionMessage($selfIdentifier, $targetIdentifier, $message);
        $this->matchService->markResolved($matchId);

        return [
            'resolved' => true,
            'message' => $message,
        ];
    }

    private function buildResolutionMessage(string $first, string $second): string
    {
        if ($first === 'reach_out' || $second === 'reach_out') {
            return "You're both open to reconnecting. Either of you may reach out in whatever way feels right.";
        }

        if ($first === 'either' && $second === 'either') {
            return 'There is mutual openness to reconnecting. If contact happens, it can be assumed to be welcome.';
        }

        if ($first === 'prefer_other' && $second === 'prefer_other') {
            return 'Mutual openness has been confirmed. No one is expected to initiate. Contact may happen naturally, or not at all.';
        }

        return 'Mutual openness has been confirmed. Either of you may initiate if and when it feels appropriate.';
    }
}
