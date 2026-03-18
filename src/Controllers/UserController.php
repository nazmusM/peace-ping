<?php

namespace App\Controllers;

use App\Services\UserService;
use App\Utils\Response;
use InvalidArgumentException;
use Exception;

class UserController
{
    public function __construct(private readonly UserService $userService) {}

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

        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';

        try {
            switch ($action) {
                case 'register':
                    $this->handleRegister($input);
                    break;
                case 'verify':
                    $this->handleVerify($input);
                    break;
                case 'status':
                    $this->handleStatus();
                    break;
                case 'logout':
                    $this->handleLogout();
                    break;
                default:
                    Response::json(['error' => 'Invalid action.'], 400);
            }
        } catch (InvalidArgumentException $e) {
            Response::json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            error_log('User registration error: ' . $e->getMessage());
            Response::json(['error' => 'Internal server error.'], 500);
        }
    }

    private function handleRegister(array $input): void
    {
        $name = trim($input['name'] ?? '');
        $phone = trim($input['phone'] ?? '');

        if ($name === '' || $phone === '') {
            Response::json(['error' => 'Name and phone number are required.'], 400);
            return;
        }

        $result = $this->userService->register($name, $phone);
        Response::json($result);
    }

    private function handleVerify(array $input): void
    {
        $code = trim($input['code'] ?? '');

        if ($code === '') {
            Response::json(['error' => 'Verification code is required.'], 400);
            return;
        }

        try {
            $result = $this->userService->verifyAndCreate($code);
            Response::json($result);
        } catch (Exception $e) {
            error_log('Verification error: ' . $e->getMessage());
            Response::json(['error' => $e->getMessage()], 400);
        }
    }

    private function handleStatus(): void
    {
        $isLoggedIn = $this->userService->isLoggedIn();
        $user = $this->userService->getCurrentUser();

        Response::json([
            'logged_in' => $isLoggedIn,
            'user' => $user
        ]);
    }

    private function handleLogout(): void
    {
        $this->userService->logout();
        Response::json(['message' => 'Logged out successfully.']);
    }

    /**
     * Parse JSON input from request body
     */
    private function parseInput(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        return $input ?? [];
    }
}
