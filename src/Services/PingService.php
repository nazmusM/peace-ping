<?php

namespace App\Services;

use App\Fingerprint;
use InvalidArgumentException;
use mysqli;

class PingService
{
    public function __construct(
        private readonly mysqli $db,
        private readonly Fingerprint $fingerprint,
        private readonly MatchService $matchService,
        private readonly NotificationService $notificationService,
        private readonly string $pepper
    ) {
    }

    public function submitPing(string $selfName, string $selfIdentifier, string $targetIdentifier): array
    {
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
            'INSERT INTO pings (self_name, fingerprint_self, fingerprint_target, created_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE self_name = VALUES(self_name), created_at = NOW()'
        );
        $insert->bind_param('sss', $normalizedName, $fingerprintSelf, $fingerprintTarget);
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
            $this->notificationService->sendPreferencePrompt(
                $selfIdentifier,
                $targetIdentifier,
                $otherNameForSelf,
                $normalizedName
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
