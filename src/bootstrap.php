<?php

declare(strict_types=1);

use App\Config\Env;

require_once __DIR__ . '/Config/Env.php';

Env::load(dirname(__DIR__) . '/.env');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

$config = require __DIR__ . '/Config/config.php';

if (($config['security']['pepper'] ?? '') === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Server security configuration is incomplete.',
    ], JSON_THROW_ON_ERROR);
    exit;
}
