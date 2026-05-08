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
        $message = 'Peace Ping: You have a private update. Please log in to the web portal.';

        foreach ($contacts as $contact) {
            $this->sendToIdentifier($contact, 'Peace Ping Match', $message);
        }
    }

    public function sendFinalPermissionMessage(string $fingerprintRecipient, array|string $contacts, ?string $message = null): void
    {
        $message ??= 'Peace Ping: You have a private update. Please log in to the web portal.';
        $recipients = is_array($contacts) ? $contacts : [$fingerprintRecipient, $contacts];

        foreach ($recipients as $contact) {
            $this->sendToIdentifier($contact, 'Peace Ping Reconnection', $message);
        }
    }

    public function sendToIdentifier(string $identifier, string $subject, string $message): void
    {
        if (!$this->looksLikePhone($identifier)) {
            throw new RuntimeException('A valid phone number is required for notifications.');
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

    private function looksLikePhone(string $identifier): bool
    {
        $clean = preg_replace('/[^0-9+]/', '', $identifier);

        return (bool) (
            preg_match('/^\+?[1-9][0-9]{6,14}$/', $clean) ||
            preg_match('/^0[0-9]{7,14}$/', $clean)
        );
    }
}
