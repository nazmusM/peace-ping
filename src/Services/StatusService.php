<?php

namespace App\Services;

use App\Fingerprint;
use InvalidArgumentException;
use mysqli;

class StatusService
{
    public function __construct(
        private readonly mysqli $db,
        private readonly Fingerprint $fingerprint,
        private readonly MatchService $matchService,
        private readonly string $pepper
    ) {
    }

    public function getStatus(string $selfIdentifier, string $targetIdentifier): array
    {
        $selfFingerprint = $this->fingerprint->fingerprint($selfIdentifier, $this->pepper);
        $targetFingerprint = $this->fingerprint->fingerprint($targetIdentifier, $this->pepper);

        if ($selfFingerprint === $targetFingerprint) {
            throw new InvalidArgumentException('Self and target identifiers must be different.');
        }

        $match = $this->matchService->getMatchByFingerprints($selfFingerprint, $targetFingerprint);
        if ($match === null) {
            return [
                'matched' => false,
                'stage' => 'awaiting_match',
                'message' => 'No mutual match yet.',
            ];
        }

        if ((string) $match['status'] === 'resolved') {
            return [
                'matched' => true,
                'stage' => 'resolved',
                'message' => $this->resolvedMessage((int) $match['id']),
            ];
        }

        $hasPreference = $this->hasPreference((int) $match['id'], $selfFingerprint);

        return [
            'matched' => true,
            'stage' => 'awaiting_preferences',
            'preference_submitted' => $hasPreference,
            'message' => 'You and the other person have both indicated openness to reconnecting. How would you like this to proceed?',
        ];
    }

    private function hasPreference(int $matchId, string $selfFingerprint): bool
    {
        $select = $this->db->prepare(
            'SELECT id FROM preferences WHERE match_id = ? AND fingerprint = ? LIMIT 1'
        );
        $select->bind_param('is', $matchId, $selfFingerprint);
        $select->execute();
        $row = $select->get_result()->fetch_assoc();
        $select->close();

        return $row !== null;
    }

    private function resolvedMessage(int $matchId): string
    {
        $select = $this->db->prepare(
            'SELECT preference FROM preferences WHERE match_id = ? ORDER BY id ASC'
        );
        $select->bind_param('i', $matchId);
        $select->execute();
        $result = $select->get_result();
        $values = [];
        while ($row = $result->fetch_assoc()) {
            $values[] = (string) $row['preference'];
        }
        $select->close();

        if (count($values) < 2) {
            return 'Mutual openness has been confirmed.';
        }

        if ($values[0] === 'reach_out' || $values[1] === 'reach_out') {
            return "You're both open to reconnecting. Either of you may reach out in whatever way feels right.";
        }

        if ($values[0] === 'either' && $values[1] === 'either') {
            return 'There is mutual openness to reconnecting. If contact happens, it can be assumed to be welcome.';
        }

        if ($values[0] === 'prefer_other' && $values[1] === 'prefer_other') {
            return 'Mutual openness has been confirmed. No one is expected to initiate. Contact may happen naturally, or not at all.';
        }

        return 'Mutual openness has been confirmed. Either of you may initiate if and when it feels appropriate.';
    }
}
