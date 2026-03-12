<?php

namespace App\Services;

use RuntimeException;

class NotificationService
{
    public function __construct(
        private readonly string $emailFrom,
        private readonly string $smsWebhookUrl
    ) {
    }

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

    private function sendToIdentifier(string $identifier, string $subject, string $message): void
    {
        $normalized = strtolower(trim($identifier));
        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false) {
            $this->sendEmail($normalized, $subject, $message);
            return;
        }

        if ($this->looksLikePhone($normalized)) {
            $this->sendSms($normalized, $message);
            return;
        }

        throw new RuntimeException('Unsupported identifier format for outbound notification.');
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
