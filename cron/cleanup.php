<?php

declare(strict_types=1);

use App\Database\Database;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$db = Database::getConnection($config['db']);
$db->begin_transaction();

try {
    // Delete matched pings 30 days after the match occurred
    $matchedPings = $db->prepare(
        "DELETE p FROM pings p
         INNER JOIN matches m ON
             (m.fingerprint_a = p.fingerprint_self AND m.fingerprint_b = p.fingerprint_target)
             OR (m.fingerprint_a = p.fingerprint_target AND m.fingerprint_b = p.fingerprint_self)
         WHERE m.created_at < (NOW() - INTERVAL 30 DAY)"
    );
    $matchedPings->execute();
    $matchedPings->close();

    // Delete unmatched pings older than 30 days
    $unmatchedPings = $db->prepare(
        "DELETE p FROM pings p
         LEFT JOIN matches m ON
             (m.fingerprint_a = p.fingerprint_self AND m.fingerprint_b = p.fingerprint_target)
             OR (m.fingerprint_a = p.fingerprint_target AND m.fingerprint_b = p.fingerprint_self)
         WHERE m.id IS NULL AND p.created_at < (NOW() - INTERVAL 30 DAY)"
    );
    $unmatchedPings->execute();
    $unmatchedPings->close();

    $statements = [
        'DELETE FROM match_preferences WHERE submitted_at < (NOW() - INTERVAL 30 DAY)',
        'DELETE FROM preferences WHERE submitted_at < (NOW() - INTERVAL 30 DAY)',
        'DELETE FROM match_tokens WHERE expires_at < NOW()',
        'DELETE FROM matches WHERE created_at < (NOW() - INTERVAL 30 DAY)',
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
