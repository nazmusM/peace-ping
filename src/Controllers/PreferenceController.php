<?php

namespace App\Controllers;

use App\Services\PreferenceService;
use App\Utils\Response;
use InvalidArgumentException;
use Throwable;

class PreferenceController
{
    public function __construct(private readonly PreferenceService $preferenceService)
    {
    }

    public function handle(): void
    {
        try {
            $payload = $this->decodeJsonBody();

            if (!isset($payload['self'], $payload['target'], $payload['preference'])) {
                Response::json(['error' => 'Missing required fields: self, target, preference.'], 400);
                return;
            }

            $result = $this->preferenceService->submitPreference(
                (string) $payload['self'],
                (string) $payload['target'],
                (string) $payload['preference']
            );
            Response::json($result, 200);
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
