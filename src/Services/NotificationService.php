<?php

namespace App\Services;

use App\Utils\Encryption;
use RuntimeException;

class NotificationService
{
    private readonly Encryption $encryption;

    public function __construct(
        private readonly SmsService $smsService,
        Encryption $encryption
    ) {
        $this->encryption = $encryption;
    }

    public function sendPreferencePrompt(string $fingerprintA, string $fingerprintB, array $contacts): void
    {
        $message = "Peace Ping: You have a mutual match! Someone you're thinking about is also thinking about you.";

        foreach ($contacts as $contact) {
            $this->sendToIdentifier($contact, 'Peace Ping Match', $message);
        }
    }

    public function sendFinalPermissionMessage(string $fingerprintRecipient, array $contacts): void
    {
        $message = "Peace Ping: Both parties have agreed to reconnect! You'll receive contact details shortly.";

        foreach ($contacts as $contact) {
            $this->sendToIdentifier($contact, 'Peace Ping Reconnection', $message);
        }
    }

    public function sendToIdentifier(string $identifier, string $subject, string $message): void
    {
        if (!$this->looksLikeUKPhone($identifier)) {
            throw new RuntimeException('Only UK phone numbers are supported for notifications.');
        }

        $this->sendSms($identifier, $message);
    }

    public function sendSmsMessage(string $phoneNumber, string $message): bool
    {
        return $this->smsService->sendSms($phoneNumber, $message);
    }

    private function sendSms(string $phoneNumber, string $message): void
    {
        $success = $this->smsService->sendSms($phoneNumber, $message);

        if (!$success) {
            throw new RuntimeException('Failed to send SMS notification.');
        }
    }

    public function sendVerificationCode(string $phoneNumber, string $code): bool
    {
        return $this->smsService->sendVerificationCode($phoneNumber, $code);
    }

    public function sendPeacePingQuestion(string $phoneNumber, int $questionNumber, string $question): bool
    {
        return $this->smsService->sendPeacePingQuestion($phoneNumber, $questionNumber, $question);
    }

    private function looksLikeUKPhone(string $identifier): bool
    {
        $clean = preg_replace('/[^0-9+]/', '', $identifier);

        return (
            preg_match('/^07[0-9]{9}$/', $clean) ||
            preg_match('/^\+447[0-9]{9}$/', $clean) ||
            preg_match('/^01[0-9]{9}$/', $clean) ||
            preg_match('/^02[0-9]{9}$/', $clean) ||
            preg_match('/^03[0-9]{9}$/', $clean) ||
            preg_match('/^\+44[12][0-9]{9}$/', $clean)
        );
    }
}
