<?php

namespace App\Controllers;

use App\Services\PeacePingService;
use App\Services\UserService;
use App\Utils\RateLimiter;
use App\Utils\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PingController
{
    public function __construct(
        private readonly PeacePingService $pingService,
        private readonly RateLimiter $rateLimiter,
        private readonly UserService $userService
    ) {}

    public function handle(string $ipAddress): void
    {
        try {
            $this->rateLimiter->enforcePingLimit($ipAddress);

            $payload = $this->decodeJsonBody();

            // Only support new format with user_id
            if (isset($payload['user_id'], $payload['target'])) {
                // Validate user_id matches authenticated session
                $currentUser = $this->userService->getCurrentUser();
                if ($currentUser === null || (int) $payload['user_id'] !== $currentUser['id']) {
                    Response::json(['error' => 'Unauthorized. You can only ping from your own account.'], 401);
                    return;
                }

                $target = trim($payload['target']);
                if (strlen($target) < 10 || strlen($target) > 20) {
                    Response::json(['error' => 'Phone number must be between 10 and 20 characters.'], 400);
                    return;
                }

                $result = $this->pingService->submitPing(
                    (int) $payload['user_id'],
                    $target
                );
            } else {
                Response::json(['error' => 'Missing required fields: user_id and target.'], 400);
                return;
            }

            Response::json($result, 200);
        } catch (InvalidArgumentException $exception) {
            Response::json(['error' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            Response::json(['error' => $exception->getMessage()], 429);
        } catch (Throwable $exception) {
            error_log('Ping error: ' . $exception->getMessage());
            Response::json(['error' => 'Internal server error.'], 500);
        }
    }

    private function decodeJsonBody(): array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON payload.');
        }

        return $decoded;
    }
}
