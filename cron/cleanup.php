<?php

declare(strict_types=1);

use App\Database\Database;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$db = Database::getConnection($config['db']);
$db->begin_transaction();

try {
    $statements = [
        'DELETE FROM match_preferences WHERE submitted_at < (NOW() - INTERVAL 30 DAY)',
        'DELETE FROM preferences WHERE submitted_at < (NOW() - INTERVAL 30 DAY)',
        'DELETE FROM match_tokens WHERE expires_at < NOW()',
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
