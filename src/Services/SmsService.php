<?php

namespace App\Services;

class SmsService
{
    private string $smsLogPath;
    private string $smsQueuePath;

    public function __construct(private readonly array $config)
    {
        $this->smsLogPath = __DIR__ . '/../../logs/sms/';
        $this->smsQueuePath = __DIR__ . '/../../queue/sms/';

        // Ensure directories exist
        $this->ensureDirectoryExists($this->smsLogPath);
        $this->ensureDirectoryExists($this->smsQueuePath);
    }

    /**
     * Send SMS message (currently creates a file for testing)
     */
    public function sendSms(string $phoneNumber, string $message): bool
    {
        try {
            $smsData = [
                'to' => $phoneNumber,
                'message' => $message,
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => 'queued',
                'id' => uniqid('sms_', true)
            ];

            // Create SMS file in queue
            $filename = $smsData['id'] . '.json';
            $filepath = $this->smsQueuePath . $filename;

            $result = file_put_contents($filepath, json_encode($smsData, JSON_PRETTY_PRINT));

            if ($result !== false) {
                $this->logSmsActivity($smsData);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->logSmsActivity([
                'error' => $e->getMessage(),
                'to' => $phoneNumber,
                'message' => $message,
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => 'failed'
            ]);
            return false;
        }
    }

    /**
     * Send Peace Ping flow chart question
     */
    public function sendPeacePingQuestion(string $phoneNumber, int $questionNumber, string $question): bool
    {
        $message = "🕊️ Peace Ping: Question {$questionNumber}\n\n{$question}\n\nReply with your choice (A, B, or C)";

        return $this->sendSms($phoneNumber, $message);
    }

    /**
     * Send verification code
     */
    public function sendVerificationCode(string $phoneNumber, string $code): bool
    {
        $message = "🔐 Peace Ping Verification\n\nYour verification code is: {$code}\n\nThis code expires in 10 minutes.";

        return $this->sendSms($phoneNumber, $message);
    }

    /**
     * Get SMS queue status
     */
    public function getQueueStatus(): array
    {
        $files = glob($this->smsQueuePath . '*.json');
        $queued = count($files);

        return [
            'queued_count' => $queued,
            'queue_path' => $this->smsQueuePath,
            'log_path' => $this->smsLogPath
        ];
    }

    /**
     * Process SMS queue (placeholder for future real SMS integration)
     */
    public function processQueue(): array
    {
        $files = glob($this->smsQueuePath . '*.json');
        $processed = 0;
        $failed = 0;

        foreach ($files as $file) {
            try {
                $smsData = json_decode(file_get_contents($file), true);

                // TODO: Replace with real SMS API call
                // For now, just mark as processed and move to log
                $smsData['status'] = 'processed';
                $smsData['processed_at'] = date('Y-m-d H:i:s');

                $this->logSmsActivity($smsData);
                unlink($file); // Remove from queue
                $processed++;
            } catch (\Exception $e) {
                $failed++;
                $this->logSmsActivity([
                    'error' => $e->getMessage(),
                    'file' => basename($file),
                    'timestamp' => date('Y-m-d H:i:s'),
                    'status' => 'failed'
                ]);
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'total' => $processed + $failed
        ];
    }

    /**
     * Get SMS logs
     */
    public function getSmsLogs(int $limit = 50): array
    {
        $files = glob($this->smsLogPath . '*.json');
        rsort($files); // Most recent first

        $logs = [];
        $count = 0;

        foreach ($files as $file) {
            if ($count >= $limit) break;

            $logData = json_decode(file_get_contents($file), true);
            $logs[] = $logData;
            $count++;
        }

        return $logs;
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function logSmsActivity(array $data): void
    {
        $filename = 'sms_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.json';
        $filepath = $this->smsLogPath . $filename;

        file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT));
    }
}
