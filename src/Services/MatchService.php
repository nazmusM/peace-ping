<?php

namespace App\Services;

use mysqli;

class MatchService
{
    public function __construct(private readonly mysqli $db) {}

    public function createOrGetMatch(string $fingerprintOne, string $fingerprintTwo, ?int $userAId = null, ?int $userBId = null): array
    {
        [$a, $b] = $this->canonicalPair($fingerprintOne, $fingerprintTwo);

        // Canonicalize user IDs to match fingerprint order
        if ($userAId !== null && $userBId !== null) {
            if ($fingerprintOne > $fingerprintTwo) {
                [$userAId, $userBId] = [$userBId, $userAId];
            }
        }

        $select = $this->db->prepare(
            'SELECT id, user_a_id, user_b_id FROM matches WHERE fingerprint_a = ? AND fingerprint_b = ? LIMIT 1'
        );
        $select->bind_param('ss', $a, $b);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc();
        $select->close();

        if ($existing !== null) {
            return [
                'id' => (int) $existing['id'],
                'created' => false,
                'user_a_id' => $existing['user_a_id'] ? (int) $existing['user_a_id'] : null,
                'user_b_id' => $existing['user_b_id'] ? (int) $existing['user_b_id'] : null,
            ];
        }

        $insert = $this->db->prepare(
            "INSERT INTO matches (fingerprint_a, fingerprint_b, user_a_id, user_b_id, status, created_at)
             VALUES (?, ?, ?, ?, 'awaiting_preferences', NOW())"
        );
        $insert->bind_param('ssii', $a, $b, $userAId, $userBId);
        $insert->execute();
        $id = (int) $this->db->insert_id;
        $insert->close();

        return [
            'id' => $id,
            'created' => true,
            'user_a_id' => $userAId,
            'user_b_id' => $userBId,
        ];
    }

    public function getMatchById(int $matchId): ?array
    {
        $select = $this->db->prepare(
            'SELECT id, fingerprint_a, fingerprint_b, user_a_id, user_b_id, status FROM matches WHERE id = ? LIMIT 1'
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
            'SELECT id, fingerprint_a, fingerprint_b, user_a_id, user_b_id, status FROM matches WHERE fingerprint_a = ? AND fingerprint_b = ? LIMIT 1'
        );
        $select->bind_param('ss', $a, $b);
        $select->execute();
        $match = $select->get_result()->fetch_assoc();
        $select->close();

        return $match ?: null;
    }

    public function getMatchByUserIds(int $userAId, int $userBId): ?array
    {
        $select = $this->db->prepare(
            'SELECT id, fingerprint_a, fingerprint_b, user_a_id, user_b_id, status 
             FROM matches 
             WHERE (user_a_id = ? AND user_b_id = ?) OR (user_a_id = ? AND user_b_id = ?) 
             LIMIT 1'
        );
        $select->bind_param('iiii', $userAId, $userBId, $userBId, $userAId);
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

    public function updateUserIds(int $matchId, int $userAId, int $userBId): void
    {
        $update = $this->db->prepare(
            "UPDATE matches SET user_a_id = ?, user_b_id = ? WHERE id = ?"
        );
        $update->bind_param('iii', $userAId, $userBId, $matchId);
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
