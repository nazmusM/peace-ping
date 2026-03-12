<?php

declare(strict_types=1);

use App\Database\Database;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$db = Database::getConnection($config['db']);
$db->begin_transaction();

try {
    $statements = [
        'DELETE FROM preferences WHERE created_at < (NOW() - INTERVAL 30 DAY)',
        'DELETE FROM notifications WHERE created_at < (NOW() - INTERVAL 30 DAY)',
        'DELETE FROM matches WHERE created_at < (NOW() - INTERVAL 30 DAY)',
        'DELETE FROM pings WHERE created_at < (NOW() - INTERVAL 30 DAY)',
        'DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 2 DAY)',
    ];

    foreach ($statements as $sql) {
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $stmt->close();
    }

    $db->commit();
    echo "Cleanup completed.\n";
} catch (Throwable $exception) {
    $db->rollback();
    fwrite(STDERR, "Cleanup failed.\n");
    exit(1);
}
