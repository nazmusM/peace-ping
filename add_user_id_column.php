<?php
// Script to add missing user_id column to pings table
require_once __DIR__ . '/src/bootstrap.php';

use App\Database\Database;

$db = Database::getConnection($config['db']);

// Check if user_id column exists in pings table
$check = $db->query("SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pings' AND COLUMN_NAME = 'user_id'");
$result = $check->fetch_assoc();
$exists = $result['count'] > 0;

if ($exists) {
    echo "✓ user_id column already exists in pings table\n";
} else {
    // Add user_id column
    try {
        $db->query("ALTER TABLE pings ADD COLUMN user_id BIGINT UNSIGNED NOT NULL AFTER id");
        echo "✓ Added user_id column to pings table\n";
        
        // Add index
        $db->query("ALTER TABLE pings ADD INDEX idx_pings_user_id (user_id)");
        echo "✓ Added index for user_id column\n";
        
        // Add foreign key if users table exists
        $db->query("ALTER TABLE pings ADD CONSTRAINT fk_pings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
        echo "✓ Added foreign key constraint\n";
        
    } catch (Exception $e) {
        echo "✗ Error adding user_id column: " . $e->getMessage() . "\n";
    }
}

echo "\nDone!\n";
