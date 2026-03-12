<?php

use App\Controllers\PingController;
use App\Controllers\PreferenceController;
use App\Controllers\StatusController;
use App\Database\Database;
use App\Fingerprint;
use App\Services\MatchService;
use App\Services\NotificationService;
use App\Services\PingService;
use App\Services\PreferenceService;
use App\Services\StatusService;
use App\Utils\RateLimiter;
use App\Utils\Response;

require_once __DIR__ . '/src/bootstrap.php';

$db = Database::getConnection($config['db']);
$fingerprint = new Fingerprint();
$matchService = new MatchService($db);
$notificationService = new NotificationService(
    $config['notifications']['email_from'],
    $config['notifications']['sms_webhook_url']
);
$pingService = new PingService(
    $db,
    $fingerprint,
    $matchService,
    $notificationService,
    $config['security']['pepper']
);
$preferenceService = new PreferenceService(
    $db,
    $fingerprint,
    $matchService,
    $notificationService,
    $config['security']['pepper']
);
$statusService = new StatusService($db, $fingerprint, $matchService, $config['security']['pepper']);
$rateLimiter = new RateLimiter(
    $db,
    $config['security']['pepper'],
    (int) $config['rate_limit']['max_pings_per_hour']
);

$pingController = new PingController($pingService, $rateLimiter);
$preferenceController = new PreferenceController($preferenceService);
$statusController = new StatusController($statusService);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'POST' && preg_match('#/api/ping$#', $path) === 1) {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $pingController->handle($clientIp);
    exit;
}

if ($method === 'POST' && preg_match('#/api/preference$#', $path) === 1) {
    $preferenceController->handle();
    exit;
}

if ($method === 'POST' && preg_match('#/api/status$#', $path) === 1) {
    $statusController->handle();
    exit;
}

if ($method !== 'GET') {
    Response::json(['error' => 'Not found.'], 404);
    exit;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Peace Ping</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=<?= time() ?>">
</head>

<body>
    <main class="shell">
        <section class="hero">
            <p class="kicker">Peace Ping</p>
            <h1>Mutual Reconnection, Without Pressure</h1>
            <p>
                Share your identifier and the other person's identifier. If both sides independently indicate openness,
                Peace Ping checks for mutual matches in the background and confirms only when mutual openness exists.
            </p>
        </section>

        <section class="grid">
            <article class="card">
                <h2>1. Send Ping</h2>
                <form id="ping-form">
                    <div class="form-group">
                        <label for="ping-self-name">Your name</label>
                        <input id="ping-self-name" name="self_name" type="text" required placeholder="Alex">
                    </div>
                    <div class="form-group">
                        <label for="ping-self">Your email or phone</label>
                        <input id="ping-self" name="self" type="text" required placeholder="you@example.com">
                    </div>
                    <div class="form-group">
                        <label for="ping-target">Other person's email or phone</label>
                        <input id="ping-target" name="target" type="text" required placeholder="+15551234567">
                    </div>
                    <button type="submit">Submit Ping</button>
                </form>
                <p id="ping-result" class="result" aria-live="polite"></p>
            </article>

            <article class="card">
                <h2>2. Check Status</h2>
                <form id="status-form">
                    <div class="form-group">
                        <label for="status-self">Your email or phone</label>
                        <input id="status-self" name="self" type="text" required>
                    </div>
                    <div class="form-group">
                        <label for="status-target">Other person's email or phone</label>
                        <input id="status-target" name="target" type="text" required>
                    </div>
                    <button type="submit">Check Status</button>
                </form>
                <p id="status-result" class="result" aria-live="polite"></p>
            </article>

            <article id="preference-card" class="card" hidden>
                <h2>3. Submit Preference</h2>
                <form id="preference-form">
                    <div class="form-group">
                        <label for="preference-self">Your email or phone</label>
                        <input id="preference-self" name="self" type="text" required>
                    </div>
                    <div class="form-group">
                        <label for="preference-target">Other person's email or phone</label>
                        <input id="preference-target" name="target" type="text" required>
                    </div>
                    <div class="form-group">
                        <label for="preference-choice">Your preference</label>
                        <select id="preference-choice" name="preference" required>
                            <option value="reach_out">I'm comfortable reaching out</option>
                            <option value="prefer_other">I'd prefer the other person reach out</option>
                            <option value="either">Either is fine</option>
                        </select>
                    </div>
                    <button type="submit">Submit Preference</button>
                </form>
                <p id="preference-result" class="result" aria-live="polite"></p>
            </article>
        </section>
    </main>

    <script src="app.js" defer></script>
</body>

</html>
