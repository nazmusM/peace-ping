<?php

namespace App\Controllers;

use App\Services\UserService;
use App\Services\SmsService;
use App\Utils\Response;
use InvalidArgumentException;
use Exception;

class UserController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly SmsService $smsService
    ) {}

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
                case 'login':
                    $this->handleLogin($input);
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
                case 'send_reset_otp':
                    $this->handleSendResetOtp($input);
                    break;
                case 'verify_reset_otp':
                    $this->handleVerifyResetOtp($input);
                    break;
                case 'reset_password':
                    $this->handleResetPassword($input);
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
        $phone = trim($input['phone'] ?? '');
        $confirmPhone = trim($input['confirm_phone'] ?? '');
        $password = trim($input['password'] ?? '');
        $confirmPassword = trim($input['confirm_password'] ?? '');
        $name = trim($input['name'] ?? 'Peace Ping User');

        if ($phone === '') {
            Response::json(['error' => 'Mobile number is required.'], 400);
            return;
        }

        if ($confirmPhone === '') {
            Response::json(['error' => 'Please confirm your mobile number.'], 400);
            return;
        }

        if ($this->userService->normalizePhone($phone) !== $this->userService->normalizePhone($confirmPhone)) {
            Response::json(['error' => 'The mobile numbers do not match. Please check both entries before continuing.'], 400);
            return;
        }

        if ($password === '') {
            Response::json(['error' => 'Password is required.'], 400);
            return;
        }

        if ($confirmPassword === '') {
            Response::json(['error' => 'Please confirm your password.'], 400);
            return;
        }

        if ($password !== $confirmPassword) {
            Response::json(['error' => 'The passwords do not match. Please check both entries before continuing.'], 400);
            return;
        }

        if (strlen(preg_replace('/\D/', '', $phone) ?? '') < 8 || strlen(preg_replace('/\D/', '', $phone) ?? '') > 15) {
            Response::json(['error' => $this->userService->getPhoneFormatGuidance()], 400);
            return;
        }

        $result = $this->userService->register($name, $phone, $password);

        Response::json($result);
    }

    private function handleLogin(array $input): void
    {
        $phone = trim($input['phone'] ?? '');
        $password = trim($input['password'] ?? '');

        if ($phone === '') {
            Response::json(['error' => 'Mobile number is required.'], 400);
            return;
        }

        if ($password === '') {
            Response::json(['error' => 'Password is required.'], 400);
            return;
        }

        if (strlen(preg_replace('/\D/', '', $phone) ?? '') < 8 || strlen(preg_replace('/\D/', '', $phone) ?? '') > 15) {
            Response::json(['error' => $this->userService->getPhoneFormatGuidance()], 400);
            return;
        }

        $result = $this->userService->loginWithPassword($phone, $password);

        Response::json($result);
    }

    private function handleVerify(array $input): void
    {
        $code = trim($input['code'] ?? '');

        if ($code === '') {
            Response::json(['error' => 'Verification code is required.'], 400);
            return;
        }

        if (!preg_match('/^[0-9]{6}$/', $code)) {
            Response::json(['error' => 'Verification code must be exactly 6 digits.'], 400);
            return;
        }

        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $pendingUserId = isset($_SESSION['pending_verification_user_id'])
                ? (int) $_SESSION['pending_verification_user_id']
                : null;
            $result = $this->userService->verifyAndCreate($code, $pendingUserId);
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

    private function handleSendResetOtp(array $input): void
    {
        $phone = trim($input['phone'] ?? '');

        if ($phone === '') {
            Response::json(['error' => 'Mobile number is required.'], 400);
            return;
        }

        $result = $this->userService->sendResetOtp($phone);
        Response::json($result);
    }

    private function handleVerifyResetOtp(array $input): void
    {
        $phone = trim($input['phone'] ?? '');
        $code = trim($input['code'] ?? '');

        if ($phone === '') {
            Response::json(['error' => 'Mobile number is required.'], 400);
            return;
        }

        if ($code === '') {
            Response::json(['error' => 'Verification code is required.'], 400);
            return;
        }

        if (!preg_match('/^[0-9]{6}$/', $code)) {
            Response::json(['error' => 'Verification code must be exactly 6 digits.'], 400);
            return;
        }

        try {
            $result = $this->userService->verifyResetOtp($phone, $code);
            Response::json($result);
        } catch (\Exception $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }
    }

    private function handleResetPassword(array $input): void
    {
        $phone = trim($input['phone'] ?? '');
        $password = trim($input['password'] ?? '');
        $confirmPassword = trim($input['confirm_password'] ?? '');

        if ($phone === '') {
            Response::json(['error' => 'Mobile number is required.'], 400);
            return;
        }

        try {
            $result = $this->userService->resetPassword($phone, $password, $confirmPassword);
            Response::json($result);
        } catch (\Exception $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }
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
