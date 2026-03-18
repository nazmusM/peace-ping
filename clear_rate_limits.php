<?php
// Script to clear rate limits for testing
require_once __DIR__ . '/src/bootstrap.php';

use App\Database\Database;

$db = Database::getConnection($config['db']);

// Clear all rate limits
try {
    $db->query("DELETE FROM rate_limits");
    echo "✓ Cleared all rate limits\n";
} catch (Exception $e) {
    echo "✗ Error clearing rate limits: " . $e->getMessage() . "\n";
}

// Show current rate limit setting
echo "\nCurrent rate limit: " . $config['rate_limit']['max_pings_per_hour'] . " pings per hour\n";
echo "\nDone!\n";
