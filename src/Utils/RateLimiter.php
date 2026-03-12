<?php

namespace App\Utils;

use mysqli;
use RuntimeException;

class RateLimiter
{
    public function __construct(
        private readonly mysqli $db,
        private readonly string $pepper,
        private readonly int $maxPingsPerHour = 5
    ) {
    }

    public function enforcePingLimit(string $ipAddress): void
    {
        $safeIp = trim($ipAddress) === '' ? '0.0.0.0' : trim($ipAddress);
        $ipHash = hash('sha256', $safeIp . $this->pepper);
        $windowStart = date('Y-m-d H:00:00');

        $upsert = $this->db->prepare(
            'INSERT INTO rate_limits (ip_hash, window_start, request_count, created_at)
             VALUES (?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE request_count = request_count + 1'
        );
        $upsert->bind_param('ss', $ipHash, $windowStart);
        $upsert->execute();
        $upsert->close();

        $check = $this->db->prepare(
            'SELECT request_count FROM rate_limits WHERE ip_hash = ? AND window_start = ? LIMIT 1'
        );
        $check->bind_param('ss', $ipHash, $windowStart);
        $check->execute();
        $result = $check->get_result()->fetch_assoc();
        $check->close();

        $currentCount = isset($result['request_count']) ? (int) $result['request_count'] : 0;
        if ($currentCount > $this->maxPingsPerHour) {
            throw new RuntimeException('Rate limit exceeded. Try again later.');
        }
    }
}
