<?php

namespace App\Services;

use RuntimeException;

class NotificationService
{
    public function __construct(
        private readonly string $emailFrom,
        private readonly string $smsWebhookUrl
    ) {}

    public function sendPreferencePrompt(
        string $identifierA,
        string $identifierB,
        string $otherNameForA,
        string $otherNameForB
    ): void {
        $messageForA = "You and {$otherNameForA} have both indicated openness to reconnecting.\nHow would you like this to proceed?\nOptions: I'm comfortable reaching out / I'd prefer the other person reach out / Either is fine.";
        $messageForB = "You and {$otherNameForB} have both indicated openness to reconnecting.\nHow would you like this to proceed?\nOptions: I'm comfortable reaching out / I'd prefer the other person reach out / Either is fine.";

        $this->sendToIdentifier($identifierA, 'Peace Ping: Preference', $messageForA);
        $this->sendToIdentifier($identifierB, 'Peace Ping: Preference', $messageForB);
    }

    public function sendFinalPermissionMessage(string $identifierA, string $identifierB, string $message): void
    {
        $this->sendToIdentifier($identifierA, 'Peace Ping: Mutual Openness Confirmed', $message);
        $this->sendToIdentifier($identifierB, 'Peace Ping: Mutual Openness Confirmed', $message);
    }

    public function sendToIdentifier(string $identifier, string $subject, string $message): void
    {
        $normalized = strtolower(trim($identifier));

        // Only UK phone numbers supported
        if ($this->looksLikeUKPhone($normalized)) {
            $this->sendSms($normalized, $message);
            return;
        }

        throw new RuntimeException('UK phone number required for notifications.');
    }

    private function looksLikeUKPhone(string $identifier): bool
    {
        // Remove spaces, parentheses, hyphens
        $cleaned = preg_replace('/[\s\-\(\)]/', '', $identifier);

        // UK phone patterns
        $patterns = [
            '/^07[0-9]{9}$/',           // Mobile: 07xxxxxxxxx
            '/^\+447[0-9]{9}$/',        // Mobile: +447xxxxxxxxx
            '/^01[0-9]{8,9}$/',         // Landline: 01xxxxxxxxx
            '/^02[0-9]{8,9}$/',         // Landline: 02xxxxxxxxx
            '/^\+441[0-9]{8,9}$/',      // Landline: +441xxxxxxxxx
            '/^\+442[0-9]{8,9}$/',      // Landline: +442xxxxxxxxx
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $cleaned)) {
                return true;
            }
        }

        return false;
    }

    private function sendEmail(string $to, string $subject, string $message): void
    {
        $headers = [
            'From: ' . $this->emailFrom,
            'Content-Type: text/plain; charset=UTF-8',
        ];

        $sent = @mail($to, $subject, $message, implode("\r\n", $headers));
        if ($sent !== true) {
            throw new RuntimeException('Email delivery failed.');
        }
    }

    private function sendSms(string $to, string $message): void
    {
        if ($this->smsWebhookUrl === '') {
            throw new RuntimeException('SMS webhook URL is not configured.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('SMS delivery requires the PHP cURL extension.');
        }

        $payload = json_encode(['to' => $to, 'message' => $message], JSON_THROW_ON_ERROR);

        $ch = curl_init($this->smsWebhookUrl);
        if ($ch === false) {
            throw new RuntimeException('SMS delivery setup failed.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('SMS delivery failed.');
        }
    }

    private function looksLikePhone(string $identifier): bool
    {
        return (bool) preg_match('/^\+?[0-9][0-9\-\s\(\)]{6,24}$/', $identifier);
    }
}
