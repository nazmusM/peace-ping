<?php

namespace App\Controllers;

use App\Services\PreferenceService;
use App\Utils\Response;
use InvalidArgumentException;
use Throwable;

class PreferenceController
{
    public function __construct(private readonly PreferenceService $preferenceService) {}

    public function handle(): void
    {
        try {
            $payload = $this->decodeJsonBody();

            if (!isset($payload['self'], $payload['target'], $payload['preference'])) {
                Response::json(['error' => 'Missing required fields: self, target, preference.'], 400);
                return;
            }

            $self = trim($payload['self']);
            $target = trim($payload['target']);
            $preference = trim($payload['preference']);

            if (empty($self) || empty($target) || empty($preference)) {
                Response::json(['error' => 'All fields are required and cannot be empty.'], 400);
                return;
            }

            if (strlen($self) < 10 || strlen($self) > 20 || strlen($target) < 10 || strlen($target) > 20) {
                Response::json(['error' => 'Phone numbers must be between 10 and 20 characters.'], 400);
                return;
            }

            $validPreferences = ['comfortable', 'prefer_other', 'either'];
            if (!in_array($preference, $validPreferences, true)) {
                Response::json(['error' => 'Invalid preference value.'], 400);
                return;
            }

            $result = $this->preferenceService->submitPreference(
                $self,
                $target,
                $preference
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
