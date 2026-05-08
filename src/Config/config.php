<?php

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'database' => getenv('DB_NAME') ?: 'peace_ping',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: (getenv('DB_PASS') ?: ''),
        'port' => getenv('DB_PORT') ?: '3306',
        'socket' => getenv('DB_SOCKET') ?: '',
    ],

    'security' => [
        'pepper' => getenv('PEACEPING_PEPPER') ?: '',
        'encryption_key' => getenv('PEACEPING_ENCRYPTION_KEY') ?: '',
    ],

    'notifications' => [
        'email_from' => getenv('PEACEPING_EMAIL_FROM') ?: 'no-reply@peaceping.local',
        'sms_webhook_url' => getenv('PEACEPING_SMS_WEBHOOK_URL') ?: '',
        'portal_url' => getenv('PEACEPING_PORTAL_URL') ?: '',
    ],

    'twilio' => [
        'account_sid' => getenv('TWILIO_ACCOUNT_SID') ?: '',
        'auth_token' => getenv('TWILIO_AUTH_TOKEN') ?: '',
        'phone_number' => getenv('TWILIO_PHONE_NUMBER') ?: '',
        'messaging_service_sid' => getenv('TWILIO_MESSAGING_SERVICE_SID') ?: '',
    ],

    'rate_limit' => [
        'max_pings_per_hour' => 20, // Increased for testing
    ],
];
