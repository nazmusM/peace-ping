<?php

namespace App\Services;

use mysqli;

class MatchService
{
    public function __construct(private readonly mysqli $db)
    {
    }

    public function createOrGetMatch(string $fingerprintOne, string $fingerprintTwo): array
    {
        [$a, $b] = $this->canonicalPair($fingerprintOne, $fingerprintTwo);

        $select = $this->db->prepare(
            'SELECT id FROM matches WHERE fingerprint_a = ? AND fingerprint_b = ? LIMIT 1'
        );
        $select->bind_param('ss', $a, $b);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc();
        $select->close();

        if ($existing !== null) {
            return [
                'id' => (int) $existing['id'],
                'created' => false,
            ];
        }

        $insert = $this->db->prepare(
            "INSERT INTO matches (fingerprint_a, fingerprint_b, status, created_at)
             VALUES (?, ?, 'awaiting_preferences', NOW())"
        );
        $insert->bind_param('ss', $a, $b);
        $insert->execute();
        $id = (int) $this->db->insert_id;
        $insert->close();

        return [
            'id' => $id,
            'created' => true,
        ];
    }

    public function getMatchById(int $matchId): ?array
    {
        $select = $this->db->prepare(
            'SELECT id, fingerprint_a, fingerprint_b, status FROM matches WHERE id = ? LIMIT 1'
        );
        $select->bind_param('i', $matchId);
        $select->execute();
        $match = $select->get_result()->fetch_assoc();
        $select->close();

        return $match ?: null;
    }

    public function getMatchByFingerprints(string $fingerprintOne, string $fingerprintTwo): ?array
    {
        [$a, $b] = $this->canonicalPair($fingerprintOne, $fingerprintTwo);

        $select = $this->db->prepare(
            'SELECT id, fingerprint_a, fingerprint_b, status FROM matches WHERE fingerprint_a = ? AND fingerprint_b = ? LIMIT 1'
        );
        $select->bind_param('ss', $a, $b);
        $select->execute();
        $match = $select->get_result()->fetch_assoc();
        $select->close();

        return $match ?: null;
    }

    public function markResolved(int $matchId): void
    {
        $update = $this->db->prepare("UPDATE matches SET status = 'resolved' WHERE id = ?");
        $update->bind_param('i', $matchId);
        $update->execute();
        $update->close();
    }

    private function canonicalPair(string $fingerprintOne, string $fingerprintTwo): array
    {
        if ($fingerprintOne <= $fingerprintTwo) {
            return [$fingerprintOne, $fingerprintTwo];
        }

        return [$fingerprintTwo, $fingerprintOne];
    }
}
