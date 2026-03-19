<?php

namespace App\Services;

class SmsService
{
    public function __construct(private readonly array $config)
    {
    }

    /**
     * Placeholder SMS sender for staging/testing.
     *
     * We intentionally do not write queue or log files here.
     * The testing flow uses the database-backed inbox instead.
     */
    public function sendSms(string $phoneNumber, string $message): bool
    {
        return true;
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
            'mode' => 'inbox_only',
            'message' => 'File-based SMS queue/logging is disabled. Use the SMS inbox for testing.'
        ];
    }

    public function processQueue(): array
    {
        return [
            'processed' => 0,
            'failed' => 0,
            'total' => 0,
            'mode' => 'inbox_only',
            'message' => 'No SMS queue is processed in staging. Messages are tested through the inbox system.'
        ];
    }

    public function getSmsLogs(int $limit = 50): array
    {
        return [];
    }
}
