<?php

namespace App\Controllers;

use App\Services\StatusService;
use App\Utils\Response;
use InvalidArgumentException;
use Throwable;

class StatusController
{
    public function __construct(private readonly StatusService $statusService)
    {
    }

    public function handle(): void
    {
        try {
            $payload = $this->decodeJsonBody();
            if (!isset($payload['self'], $payload['target'])) {
                Response::json(['error' => 'Missing required fields: self, target.'], 400);
                return;
            }

            $result = $this->statusService->getStatus((string) $payload['self'], (string) $payload['target']);
            Response::json($result);
        } catch (InvalidArgumentException $exception) {
            Response::json(['error' => $exception->getMessage()], 422);
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
