<?php

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'database' => getenv('DB_NAME') ?: 'peace_ping',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: (getenv('DB_PASS') ?: ''),
        'port' => getenv('DB_PORT') ?: '3306',
        'socket' => getenv('DB_SOCKET') ?: '',
    ],

    'security' => [
        'pepper' => getenv('PEACEPING_PEPPER') ?: '',
    ],

    'notifications' => [
        'email_from' => getenv('PEACEPING_EMAIL_FROM') ?: 'no-reply@peaceping.local',
        'sms_webhook_url' => getenv('PEACEPING_SMS_WEBHOOK_URL') ?: '',
    ],

    'rate_limit' => [
        'max_pings_per_hour' => 5,
    ],
];
