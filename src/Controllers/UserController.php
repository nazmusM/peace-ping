<?php

namespace App\Controllers;

use App\Services\UserService;
use App\Utils\Response;
use InvalidArgumentException;

class UserController
{
    public function __construct(private readonly UserService $userService)
    {
    }

    /**
     * Handle user registration request
     * POST /api/register
     * 
     * Expected JSON input:
     * {
     *   "contact": "email_or_phone"
     * }
     */
    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $input = $this->getJsonInput();
            
            if (!isset($input['contact']) || !is_string($input['contact'])) {
                Response::json(['error' => 'Contact information is required.'], 400);
                return;
            }

            $contact = trim($input['contact']);
            if ($contact === '') {
                Response::json(['error' => 'Contact information cannot be empty.'], 400);
                return;
            }

            $result = $this->userService->registerUser($contact);

            Response::json([
                'user_id' => $result['user_id'],
                'created' => $result['created'],
                'message' => $result['message']
            ]);

        } catch (InvalidArgumentException $e) {
            Response::json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            error_log('User registration error: ' . $e->getMessage());
            Response::json(['error' => 'Internal server error.'], 500);
        }
    }

    /**
     * Parse JSON input from request body
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        if ($input === false) {
            throw new InvalidArgumentException('Invalid request body.');
        }

        $decoded = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON format.');
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Invalid request format.');
        }

        return $decoded;
    }
}
