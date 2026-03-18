<?php
// Script to add missing user columns to matches table
require_once __DIR__ . '/src/bootstrap.php';

use App\Database\Database;

$db = Database::getConnection($config['db']);

// Check and add user_a_id column
$checkA = $db->query("SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'matches' AND COLUMN_NAME = 'user_a_id'");
$resultA = $checkA->fetch_assoc();
$existsA = $resultA['count'] > 0;

if (!$existsA) {
    try {
        $db->query("ALTER TABLE matches ADD COLUMN user_a_id BIGINT UNSIGNED NULL AFTER fingerprint_b");
        echo "✓ Added user_a_id column to matches table\n";
        $db->query("ALTER TABLE matches ADD INDEX idx_matches_user_a (user_a_id)");
        echo "✓ Added index for user_a_id column\n";
    } catch (Exception $e) {
        echo "✗ Error adding user_a_id column: " . $e->getMessage() . "\n";
    }
} else {
    echo "✓ user_a_id column already exists in matches table\n";
}

// Check and add user_b_id column
$checkB = $db->query("SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'matches' AND COLUMN_NAME = 'user_b_id'");
$resultB = $checkB->fetch_assoc();
$existsB = $resultB['count'] > 0;

if (!$existsB) {
    try {
        $db->query("ALTER TABLE matches ADD COLUMN user_b_id BIGINT UNSIGNED NULL AFTER user_a_id");
        echo "✓ Added user_b_id column to matches table\n";
        $db->query("ALTER TABLE matches ADD INDEX idx_matches_user_b (user_b_id)");
        echo "✓ Added index for user_b_id column\n";
    } catch (Exception $e) {
        echo "✗ Error adding user_b_id column: " . $e->getMessage() . "\n";
    }
} else {
    echo "✓ user_b_id column already exists in matches table\n";
}

echo "\nDone!\n";
