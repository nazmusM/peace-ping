<?php

namespace App\Services;

class SmsService
{
    private string $accountSid;
    private string $authToken;
    private string $fromNumber;
    private string $messagingServiceSid;

    public function __construct(private readonly array $config)
    {
        $this->accountSid = $config['twilio']['account_sid'] ?? '';
        $this->authToken = $config['twilio']['auth_token'] ?? '';
        $this->fromNumber = $config['twilio']['phone_number'] ?? '';
        $this->messagingServiceSid = $config['twilio']['messaging_service_sid'] ?? '';
    }

    /**
     * Send SMS via Twilio REST API using cURL
     */
    public function sendSms(string $phoneNumber, string $message): bool
    {
        if (empty($this->accountSid) || empty($this->authToken)) {
            error_log('Twilio credentials are not configured.');
            return false;
        }

        if (empty($this->fromNumber) && empty($this->messagingServiceSid)) {
            error_log('Twilio sender is not configured. Set TWILIO_PHONE_NUMBER or TWILIO_MESSAGING_SERVICE_SID.');
            return false;
        }

        $phoneNumber = $this->formatPhoneNumber($phoneNumber);
        if ($phoneNumber === null) {
            error_log('Invalid SMS recipient phone number.');
            return false;
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";

        $payload = [
            'To' => $phoneNumber,
            'Body' => $message
        ];

        if ($this->messagingServiceSid !== '') {
            $payload['MessagingServiceSid'] = $this->messagingServiceSid;
        } else {
            $payload['From'] = $this->fromNumber;
        }

        $postData = http_build_query($payload);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->accountSid . ':' . $this->authToken);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('Twilio cURL error: ' . $error);
            return false;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        error_log('Twilio API error. HTTP Code: ' . $httpCode . ', Response: ' . $response);
        return false;
    }

    public function sendPeacePingQuestion(string $phoneNumber, int $questionNumber, string $question): bool
    {
        $message = "Peace Ping: Question {$questionNumber}\n\n{$question}\n\nReply with your choice (A, B, or C)";

        return $this->sendSms($phoneNumber, $message);
    }

    public function sendVerificationCode(string $phoneNumber, string $code): bool
    {
        $message = "Peace Ping Verification\n\nYour verification code is: {$code}\n\nThis code expires in 10 minutes.";

        return $this->sendSms($phoneNumber, $message);
    }

    public function getQueueStatus(): array
    {
        return [
            'queued_count' => 0,
            'mode' => 'twilio_direct',
            'message' => 'SMS is sent directly via Twilio API.'
        ];
    }

    public function processQueue(): array
    {
        return [
            'processed' => 0,
            'failed' => 0,
            'total' => 0,
            'mode' => 'twilio_direct',
            'message' => 'SMS is sent directly via Twilio API. No queue processing needed.'
        ];
    }

    public function getSmsLogs(int $limit = 50): array
    {
        return [];
    }

    private function formatPhoneNumber(string $phoneNumber): ?string
    {
        $phoneNumber = trim($phoneNumber);
        $hasPlus = str_starts_with($phoneNumber, '+');
        $digits = preg_replace('/\D/', '', $phoneNumber) ?? '';

        if ($digits === '') {
            return null;
        }

        if ($hasPlus) {
            $formatted = '+' . $digits;
        } elseif (str_starts_with($digits, '00')) {
            $formatted = '+' . substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $formatted = '+44' . substr($digits, 1);
        } else {
            $formatted = '+' . $digits;
        }

        return preg_match('/^\+[1-9]\d{6,14}$/', $formatted) ? $formatted : null;
    }
}
