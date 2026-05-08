<?php

namespace App\Services;

class SmsService
{
    private string $accountSid;
    private string $authToken;
    private string $fromNumber;

    public function __construct(private readonly array $config)
    {
        $this->accountSid = $config['twilio']['account_sid'] ?? '';
        $this->authToken = $config['twilio']['auth_token'] ?? '';
        $this->fromNumber = $config['twilio']['phone_number'] ?? 'PeacePing'; // Use a friendly name for the sender ID in staging
    }

    /**
     * Send SMS via Twilio REST API using cURL
     */
    public function sendSms(string $phoneNumber, string $message): bool
    {
        error_log('SmsService::sendSms called with phone: ' . $phoneNumber);
        error_log('Twilio config - SID: ' . substr($this->accountSid, 0, 8) . '..., From: ' . $this->fromNumber);

        // If Twilio credentials are not configured, fall back to simulation mode
        if (empty($this->accountSid) || empty($this->authToken) || empty($this->fromNumber)) {
            error_log('Twilio credentials not configured. SMS sending simulated.');
            return true;
        }

        // Format phone number (remove non-numeric characters, ensure it starts with +)
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
        if (!str_starts_with($phoneNumber, '+')) {
            $phoneNumber = '+' . $phoneNumber;
        }

        error_log('Formatted phone number: ' . $phoneNumber);

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";

        $postData = http_build_query([
            'From' => $this->fromNumber,
            'To' => $phoneNumber,
            'Body' => $message
        ]);

        error_log('Twilio API URL: ' . $url);
        error_log('POST data: From=' . $this->fromNumber . ', To=' . $phoneNumber);

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

        error_log('Twilio response - HTTP Code: ' . $httpCode . ', cURL error: ' . ($error ?: 'none'));
        error_log('Twilio response body: ' . $response);

        if ($error) {
            error_log('Twilio cURL error: ' . $error);
            return false;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $responseData = json_decode($response, true);
            error_log('Twilio SMS sent successfully. SID: ' . ($responseData['sid'] ?? 'unknown'));
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
}
