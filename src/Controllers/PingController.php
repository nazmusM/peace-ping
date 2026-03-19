<?php

namespace App\Controllers;

use App\Services\PeacePingService;
use App\Utils\RateLimiter;
use App\Utils\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PingController
{
    public function __construct(
        private readonly PeacePingService $pingService,
        private readonly RateLimiter $rateLimiter
    ) {}

    public function handle(string $ipAddress): void
    {
        // Debug: Log to error file
        error_log("DEBUG: PingController handle called - IP: $ipAddress");

        try {
            $this->rateLimiter->enforcePingLimit($ipAddress);
            error_log("DEBUG: Rate limit passed");

            $payload = $this->decodeJsonBody();
            error_log("DEBUG: Payload decoded: " . json_encode($payload));

            // Only support new format with user_id
            if (isset($payload['user_id'], $payload['target'])) {
                error_log("DEBUG: Calling submitPing with user_id: " . $payload['user_id'] . ", target: " . $payload['target']);

                $result = $this->pingService->submitPing(
                    (int) $payload['user_id'],
                    (string) $payload['target']
                );

                error_log("DEBUG: submitPing result: " . json_encode($result));
            } else {
                error_log("DEBUG: Missing required fields");
                Response::json(['error' => 'Missing required fields: user_id and target.'], 400);
                return;
            }

            Response::json($result, 200);
        } catch (InvalidArgumentException $exception) {
            error_log("DEBUG: InvalidArgumentException: " . $exception->getMessage());
            Response::json(['error' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            error_log("DEBUG: RuntimeException: " . $exception->getMessage());
            Response::json(['error' => $exception->getMessage()], 429);
        } catch (Throwable $exception) {
            error_log("DEBUG: Throwable: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
            error_log("DEBUG: Stack trace: " . $exception->getTraceAsString());
            Response::json(['error' => 'Internal server error.'], 500);
        }
    }

    private function decodeJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        error_log("DEBUG: Raw input: " . $raw);

        if ($raw === false || trim($raw) === '') {
            error_log("DEBUG: Empty input received");
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("DEBUG: JSON decode error: " . json_last_error_msg());
            throw new InvalidArgumentException('Invalid JSON payload.');
        }

        error_log("DEBUG: JSON decoded successfully: " . json_encode($decoded));
        return $decoded;
    }
}
