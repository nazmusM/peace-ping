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
    private string $rawBody = '';

    public function __construct(
        private readonly PeacePingService $pingService,
        private readonly RateLimiter $rateLimiter,
        private readonly UserService $userService
    ) {}

    public function handle(string $ipAddress): void
    {
        $this->rawBody = file_get_contents('php://input') ?: '';

        try {
            $this->rateLimiter->enforcePingLimit($ipAddress);

            $payload = $this->decodeJsonBody();

            // Only support new format with user_id
            if (isset($payload['user_id'], $payload['target'], $payload['confirm_target'])) {
                // Validate user_id matches authenticated session
                $currentUser = $this->userService->getCurrentUser();
                if ($currentUser === null || (int) $payload['user_id'] !== $currentUser['id']) {
                    Response::json(['error' => 'Unauthorized. You can only ping from your own account.'], 401);
                    return;
                }

                $target = trim($payload['target']);
                $confirmTarget = trim($payload['confirm_target']);
                $digits = preg_replace('/\D/', '', $target) ?? '';
                if (strlen($digits) < 8 || strlen($digits) > 15) {
                    Response::json(['error' => 'Please enter a mobile number as 07xxx xxxxxx, +447xxx xxxxxx, or +[country code][number].'], 400);
                    return;
                }

                if ($this->userService->normalizePhone($target) !== $this->userService->normalizePhone($confirmTarget)) {
                    Response::json(['error' => 'The recipient numbers do not match. Please check both entries before sending.'], 400);
                    return;
                }

                $result = $this->pingService->submitPing(
                    (int) $payload['user_id'],
                    $target,
                    trim($payload['recipient_name'] ?? '')
                );
            } else {
                Response::json(['error' => 'Missing required fields: user_id, target, and confirm_target.'], 400);
                return;
            }

            Response::json($result, 200);
        } catch (InvalidArgumentException $exception) {
            $message = 'Ping invalid argument: ' . $exception->getMessage();
            $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'error_log.txt';
            file_put_contents($path, date('c') . ' ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
            error_log($message);
            Response::json(['error' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            $message = 'Ping runtime exception: ' . $exception->getMessage();
            $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'error_log.txt';
            file_put_contents($path, date('c') . ' ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
            error_log($message);
            Response::json(['error' => $exception->getMessage()], 429);
        } catch (Throwable $exception) {
            $message = 'Ping error: ' . $exception->getMessage();
            $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'error_log.txt';
            file_put_contents($path, date('c') . ' ' . $message . ' trace=' . $exception->getTraceAsString() . PHP_EOL, FILE_APPEND | LOCK_EX);
            error_log($message . ' trace=' . $exception->getTraceAsString());
            Response::json(['error' => 'Internal server error.'], 500);
        }
    }

    private function decodeJsonBody(): array
    {
        $raw = $this->rawBody;

        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON payload.');
        }

        return $decoded;
    }
}
