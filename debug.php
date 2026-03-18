<?php
// Error debugging script for Peace Ping
require_once __DIR__ . '/src/bootstrap.php';

use App\Database\Database;
use App\Fingerprint;
use App\Utils\Encryption;
use App\Services\UserService;
use App\Services\SmsService;
use App\Services\NotificationService;
use App\Services\MatchService;
use App\Services\PingService;

// Enable all error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set custom error handler
set_error_handler(function ($severity, $message, $file, $line) {
    $errorTypes = [
        E_ERROR => 'Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_COMPILE_ERROR => 'Compile Error',
        E_USER_ERROR => 'User Error'
    ];

    $errorType = $errorTypes[$severity] ?? 'Unknown';

    echo "=== PEACE PING ERROR ===\n";
    echo "Type: $errorType\n";
    echo "Severity: $severity\n";
    echo "Message: $message\n";
    echo "File: $file\n";
    echo "Line: $line\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Request: " . ($_SERVER['REQUEST_METHOD'] ?? 'Unknown') . " " . ($_SERVER['REQUEST_URI'] ?? 'Unknown') . "\n";
    echo "User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown') . "\n";
    echo "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "\n";
    echo "========================\n\n";

    // Log to file as well
    $logMessage = sprintf(
        "[%s] %s: %s in %s on line %d - %s",
        date('Y-m-d H:i:s'),
        $errorType,
        $message,
        $file,
        $line,
        $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI']
    );

    file_put_contents(__DIR__ . '/error_log.txt', $logMessage, FILE_APPEND);
});

// Test database connection
try {
    $db = Database::getConnection($config['db']);
    echo "✓ Database connection: OK\n";

    // Test basic query
    $result = $db->query("SELECT 1 as test");
    echo "✓ Basic query: OK\n";

    // Test services
    $fingerprint = new Fingerprint();
    echo "✓ Fingerprint service: OK\n";

    $encryption = new Encryption($config['security']['encryption_key'] ?? '');
    echo "✓ Encryption service: OK\n";

    $notificationService = new NotificationService(new SmsService($config), $encryption);
    echo "✓ Notification service: OK\n";

    $userService = new UserService($db, $fingerprint, $encryption, $notificationService, $config['security']['pepper']);
    echo "✓ User service: OK\n";

    $matchService = new MatchService($db);
    echo "✓ Match service: OK\n";

    $pingService = new PingService(
        $db,
        $fingerprint,
        $userService,
        $matchService,
        $notificationService,
        $config['security']['pepper']
    );
    echo "✓ Ping service: OK\n";

    echo "\n=== ALL SERVICES INITIALIZED SUCCESSFULLY ===\n";
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
