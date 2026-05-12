<?php
$env = getenv('ENV') ?: 'development';
return [
    'db' => [
        'host' => $env=== 'production' ? getenv('DB_HOST') : 'localhost',
        'database' => $env=== 'production' ? getenv('DB_NAME') : 'peace_ping',
        'user' => $env=== 'production' ? getenv('DB_USER') : 'root',
        'password' => $env=== 'production' ? getenv('DB_PASS') :  '',
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
