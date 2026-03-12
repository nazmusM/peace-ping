<?php

namespace App\Services;

use mysqli;

class MatchWorkerService
{
    public function __construct(
        private readonly mysqli $db,
        private readonly MatchService $matchService
    ) {
    }

    public function run(): int
    {
        $query = $this->db->prepare(
            "SELECT DISTINCT
                LEAST(p1.fingerprint_self, p1.fingerprint_target) AS fingerprint_a,
                GREATEST(p1.fingerprint_self, p1.fingerprint_target) AS fingerprint_b
             FROM pings p1
             INNER JOIN pings p2
                ON p1.fingerprint_self = p2.fingerprint_target
               AND p1.fingerprint_target = p2.fingerprint_self
             WHERE p1.fingerprint_self < p1.fingerprint_target"
        );
        $query->execute();
        $result = $query->get_result();

        $created = 0;
        while ($row = $result->fetch_assoc()) {
            $match = $this->matchService->createOrGetMatch(
                (string) $row['fingerprint_a'],
                (string) $row['fingerprint_b']
            );

            if ($match['created'] === true) {
                $created++;
            }
        }
        $query->close();

        return $created;
    }
}
