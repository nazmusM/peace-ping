<?php

namespace App\Controllers;

use App\Services\PingService;
use App\Utils\RateLimiter;
use App\Utils\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PingController
{
    public function __construct(
        private readonly PingService $pingService,
        private readonly RateLimiter $rateLimiter
    ) {}

    public function handle(string $ipAddress): void
    {
        try {
            $this->rateLimiter->enforcePingLimit($ipAddress);
            $payload = $this->decodeJsonBody();

            // Support both old format (legacy) and new format (user_id)
            if (isset($payload['user_id'], $payload['target'])) {
                // New format with user_id
                $result = $this->pingService->submitPing(
                    (int) $payload['user_id'],
                    (string) $payload['target']
                );
            } elseif (isset($payload['self_name'], $payload['self'], $payload['target'])) {
                // Legacy format
                $result = $this->pingService->submitPingLegacy(
                    (string) $payload['self_name'],
                    (string) $payload['self'],
                    (string) $payload['target']
                );
            } else {
                Response::json(['error' => 'Missing required fields: user_id and target, or self_name, self, and target.'], 400);
                return;
            }

            Response::json($result, 200);
        } catch (InvalidArgumentException $exception) {
            Response::json(['error' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            Response::json(['error' => $exception->getMessage()], 429);
        } catch (Throwable $exception) {
            Response::json(['error' => 'Internal server error.'], 500);
        }
    }

    private function decodeJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
