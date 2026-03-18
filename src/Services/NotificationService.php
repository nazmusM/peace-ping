<?php

namespace App\Services;

use App\Utils\Encryption;
use InvalidArgumentException;
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

    /**
     * Send preference prompt to both parties
     */
    public function sendPreferencePrompt(string $fingerprintA, string $fingerprintB, array $contacts): void
    {
        $message = "🕊️ Peace Ping: You have a mutual match! Someone you're thinking about is also thinking about you.";

        // Send to both contacts
        foreach ($contacts as $contact) {
            $this->sendToIdentifier($contact, 'Peace Ping Match', $message);
        }
    }

    /**
     * Send final permission message
     */
    public function sendFinalPermissionMessage(string $fingerprintRecipient, array $contacts): void
    {
        $message = "🕊️ Peace Ping: Both parties have agreed to reconnect! You'll receive contact details shortly.";

        foreach ($contacts as $contact) {
            $this->sendToIdentifier($contact, 'Peace Ping Reconnection', $message);
        }
    }

    /**
     * Send to identifier (phone number only)
     */
    public function sendToIdentifier(string $identifier, string $subject, string $message): void
    {
        if (!$this->looksLikeUKPhone($identifier)) {
            throw new RuntimeException('Only UK phone numbers are supported for notifications.');
        }

        $this->sendSms($identifier, $message);
    }

    /**
     * Send SMS message
     */
    private function sendSms(string $phoneNumber, string $message): void
    {
        $success = $this->smsService->sendSms($phoneNumber, $message);

        if (!$success) {
            throw new RuntimeException('Failed to send SMS notification.');
        }
    }

    /**
     * Send verification code via SMS
     */
    public function sendVerificationCode(string $phoneNumber, string $code): bool
    {
        return $this->smsService->sendVerificationCode($phoneNumber, $code);
    }

    /**
     * Send Peace Ping flow chart question
     */
    public function sendPeacePingQuestion(string $phoneNumber, int $questionNumber, string $question): bool
    {
        return $this->smsService->sendPeacePingQuestion($phoneNumber, $questionNumber, $question);
    }

    /**
     * Check if identifier looks like a UK phone number
     */
    private function looksLikeUKPhone(string $identifier): bool
    {
        $clean = preg_replace('/[^0-9+]/', '', $identifier);

        // UK mobile: 07xxx xxxxxx or +447xx xxxxxx
        // UK landline: 01xxx, 02xxx, 03xxx
        return (
            preg_match('/^07[0-9]{9}$/', $clean) ||           // 07xxx xxxxxx
            preg_match('/^\+447[0-9]{9}$/', $clean) ||       // +447xx xxxxxx
            preg_match('/^01[0-9]{9}$/', $clean) ||           // 01xxx xxxxxx
            preg_match('/^02[0-9]{9}$/', $clean) ||           // 02xxx xxxxxx
            preg_match('/^03[0-9]{9}$/', $clean) ||           // 03xxx xxxxxx
            preg_match('/^\+44[12][0-9]{9}$/', $clean)        // +441xxx, +442xxx, +443xxx
        );
    }
}
