<?php

declare(strict_types=1);

use App\Database\Database;
use App\Services\MatchService;
use App\Services\MatchWorkerService;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$db = Database::getConnection($config['db']);

try {
    $matchService = new MatchService($db);
    $worker = new MatchWorkerService($db, $matchService);
    $created = $worker->run();
    echo "Match worker completed. New matches: {$created}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Match worker failed.\n");
    exit(1);
}
