<?php
// SMS Admin Interface
require_once __DIR__ . '/src/bootstrap.php';

use App\Services\SmsService;

$smsService = new SmsService($config);

$action = $_GET['action'] ?? 'status';

switch ($action) {
    case 'status':
        $status = $smsService->getQueueStatus();
        echo json_encode($status, JSON_PRETTY_PRINT);
        break;
        
    case 'process':
        $result = $smsService->processQueue();
        echo json_encode($result, JSON_PRETTY_PRINT);
        break;
        
    case 'logs':
        $logs = $smsService->getSmsLogs(20);
        echo json_encode($logs, JSON_PRETTY_PRINT);
        break;
        
    default:
        echo json_encode(['error' => 'Invalid action'], 400);
}
?>
