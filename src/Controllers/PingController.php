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
    ) {
    }

    public function handle(string $ipAddress): void
    {
        try {
            $this->rateLimiter->enforcePingLimit($ipAddress);
            $payload = $this->decodeJsonBody();

            if (!isset($payload['self_name'], $payload['self'], $payload['target'])) {
                Response::json(['error' => 'Missing required fields: self_name, self, target.'], 400);
                return;
            }

            $result = $this->pingService->submitPing(
                (string) $payload['self_name'],
                (string) $payload['self'],
                (string) $payload['target']
            );
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
