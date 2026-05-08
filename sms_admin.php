<?php
// SMS Admin Interface
require_once __DIR__ . '/src/bootstrap.php';

use App\Services\SmsService;

header('Content-Type: application/json; charset=utf-8');

$smsService = new SmsService($config);
$action = $_GET['action'] ?? 'status';

switch ($action) {
    case 'status':
        echo json_encode($smsService->getQueueStatus(), JSON_PRETTY_PRINT);
        break;

    case 'process':
        echo json_encode($smsService->processQueue(), JSON_PRETTY_PRINT);
        break;

    case 'logs':
        echo json_encode([
            'logs' => $smsService->getSmsLogs(20),
            'mode' => 'twilio_direct',
            'message' => 'SMS is sent directly via Twilio API. No file-based logs available.'
        ], JSON_PRETTY_PRINT);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action'], JSON_PRETTY_PRINT);
}
