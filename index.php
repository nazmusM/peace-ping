<?php
// Main template system for Peace Ping
require_once __DIR__ . '/src/bootstrap.php';

use App\Database\Database;
use App\Fingerprint;
use App\Utils\Encryption;
use App\Utils\RateLimiter;
use App\Utils\Response;
use App\Services\SmsService;
use App\Services\MatchService;
use App\Services\NotificationService;
use App\Services\UserService;
use App\Services\PingService;
use App\Services\PeacePingService;
use App\Controllers\UserController;
use App\Controllers\PingController;

// Initialize services
$db = Database::getConnection($config['db']);
$fingerprint = new Fingerprint();
$encryption = new Encryption($config['security']['encryption_key'] ?? '');
$smsService = new SmsService($config);
$matchService = new MatchService($db);
$notificationService = new NotificationService($smsService, $encryption);
$userService = new UserService($db, $fingerprint, $encryption, $notificationService, $config['security']['pepper']);
$pingService = new PingService(
    $db,
    $fingerprint,
    $userService,
    $matchService,
    $notificationService,
    $config['security']['pepper']
);
$peacePingService = new PeacePingService(
    $db,
    $fingerprint,
    $userService,
    $notificationService,
    $config['security']['pepper'],
    $config['notifications']['portal_url'] ?? ''
);
$rateLimiter = new RateLimiter(
    $db,
    $config['security']['pepper'],
    (int) $config['rate_limit']['max_pings_per_hour']
);

// Error reporting configuration
ini_set('display_errors', 0);  // Don't display errors to users
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);      // Log errors to file
ini_set('error_log', __DIR__ . '/error_log.txt'); // Write PHP error_log output to local file
error_reporting(E_ALL);        // Report all errors

// Global exception handler for debugging uncaught exceptions
set_exception_handler(function (Throwable $exception) {
    $message = sprintf(
        '[%s] Uncaught exception: %s in %s on line %d trace=%s\n',
        date('c'),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );
    file_put_contents(__DIR__ . '/error_log.txt', $message, FILE_APPEND | LOCK_EX);
    error_log($message);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $message = sprintf(
            '[%s] Shutdown error: %s in %s on line %d\n',
            date('c'),
            $error['message'],
            $error['file'],
            $error['line']
        );
        file_put_contents(__DIR__ . '/error_log.txt', $message, FILE_APPEND | LOCK_EX);
        error_log($message);
    }
});

// Custom error handler for production
set_error_handler(function ($severity, $message, $file, $line) {
    $errorTypes = [
        E_ERROR => 'Fatal Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice'
    ];

    $errorType = $errorTypes[$severity] ?? 'Unknown';

    // Log to error file
    $logMessage = sprintf(
        "[%s] %s: %s in %s on line %d - %s",
        date('Y-m-d H:i:s'),
        $errorType,
        $message,
        $file,
        $line,
        $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI']
    );

    file_put_contents(__DIR__ . '/error_log.txt', $logMessage, FILE_APPEND);

    // Don't display errors in production
    return false;
});

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// Session configuration - persistent across browser closes (30 days)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_lifetime', 2592000);
    ini_set('session.gc_maxlifetime', 2592000);
}

// Ensure required tables exist
ensureLoginAttemptsTable($db);

// Page content functions
function renderPage(string $title, string $content, string $page = 'home'): void
{
    global $userService;

    $currentUser = $userService->getCurrentUser();
    $isLoggedIn = $currentUser !== null;
    $displayContact = $isLoggedIn ? maskMobileNumber((string) ($currentUser['contact'] ?? '')) : '';
    $activeNav = [
        'home' => 'Home',
        'how-it-works' => 'How It Works',
        'ping' => 'Send Ping',
        'faq' => 'FAQ',
        'dashboard' => 'Dashboard',
        'contact' => 'Contact'
    ];
    if (!$isLoggedIn) {
        $activeNav = [
            'home' => 'Home',
            'how-it-works' => 'How It Works',
            'register' => 'Register',
            'login' => 'Login',
            'ping' => 'Send Ping',
            'faq' => 'FAQ',
            'dashboard' => 'Dashboard',
            'contact' => 'Contact'
        ];
    }

?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://fonts.googleapis.com; img-src 'self' data: https:; connect-src 'self';">
        <meta name="description" content="Peace Ping - Reconnect with people you care about through anonymous, thoughtful communication">
        <meta name="keywords" content="reconnect, communication, peace, anonymous, thoughtful">
        <meta name="author" content="Peace Ping">
        <title>Peace Ping<?php if ($page !== 'home') echo ' - ' . ucfirst($page); ?></title>
        <link rel="stylesheet" href="/styles.css?v=<?= time(); ?>">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="icon" type="image/x-icon" href="/favicon.ico">
    </head>

    <body>
        <header class="header">
            <nav class="nav">
                <div class="nav-brand">
                    <h1>Peace Ping</h1>
                    <?php if ($isLoggedIn): ?>
                        <span class="account-chip">Signed in as <?php echo htmlspecialchars($displayContact); ?></span>
                    <?php else: ?>
                        <span class="account-chip">Not signed in</span>
                    <?php endif; ?>
                </div>
                <ul class="nav-links">
                    <?php foreach ($activeNav as $route => $label): ?>
                        <li>
                            <a href="/<?php echo $route; ?>"
                                <?php echo $route === $page ? 'class="active"' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($isLoggedIn): ?>
                        <li><button type="button" class="nav-logout" id="logout-button">Logout</button></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </header>

        <main class="container">
            <?php echo $content; ?>
        </main>

        <footer class="footer">
                <p>&copy; <?php echo date('Y') ?> Peace Ping. Reconnecting people thoughtfully.</p>
        </footer>
        <!-- Cookie consent banner -->
        <div id="cookie-banner" class="cookie-banner" role="dialog" aria-label="Cookie consent">
            <div class="cookie-banner-content">
                <div class="cookie-banner-text">
                    <strong><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="9" cy="9" r="1" fill="currentColor"/><circle cx="15" cy="8" r="1" fill="currentColor"/><circle cx="8" cy="14" r="1" fill="currentColor"/><circle cx="14" cy="15" r="1" fill="currentColor"/><circle cx="12" cy="13" r="1" fill="currentColor"/></svg> We use cookies</strong>
                    <p>We store a session cookie to keep you logged in and a preference cookie to remember your phone number. No tracking or analytics cookies are used.</p>
                </div>
                <button id="cookie-accept" class="btn btn-sm">Accept</button>
            </div>
        </div>
        <!-- Modal -->
        <div id="app-modal" class="modal-overlay">
            <div class="modal-card" role="dialog" aria-modal="true">
                <div class="modal-icon" id="modal-icon"></div>
                <h3 id="modal-title" class="modal-title"></h3>
                <p id="modal-message" class="modal-message"></p>
                <div class="modal-actions" id="modal-actions"></div>
            </div>
        </div>

        <script>
            const modal = document.getElementById('app-modal');
            const modalIcon = document.getElementById('modal-icon');
            const modalTitle = document.getElementById('modal-title');
            const modalMsg = document.getElementById('modal-message');
            const modalActions = document.getElementById('modal-actions');

            function openModal() {
                modal.classList.add('open');
            }

            function closeModal() {
                modal.classList.remove('open');
            }

            // Password show/hide toggle
            document.addEventListener('click', function(e) {
                var toggle = e.target.closest('.password-toggle');
                if (!toggle) return;
                var targetId = toggle.getAttribute('data-target');
                var input = document.getElementById(targetId);
                if (!input) return;
                var isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                var eye = toggle.querySelector('.eye-icon');
                var eyeSlash = toggle.querySelector('.eye-slash-icon');
                if (eye) eye.style.display = isPassword ? 'none' : '';
                if (eyeSlash) eyeSlash.style.display = isPassword ? '' : 'none';
                toggle.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });

            async function showConfirm(title, message, confirmText) {
                return new Promise((resolve) => {
                    modalIcon.textContent = '?';
                    modalIcon.style.background = 'rgba(217, 119, 6, 0.12)';
                    modalIcon.style.color = '#d97706';
                    modalTitle.textContent = title;
                    modalMsg.textContent = message;

                    modalActions.innerHTML = `
                        <button class="btn btn-sm btn-modal-cancel" id="modal-cancel">Cancel</button>
                        <button class="btn btn-sm btn-modal-confirm" id="modal-confirm">${confirmText || 'Yes, Cancel Ping'}</button>
                    `;

                    openModal();

                    const onCancel = () => { closeModal(); resolve(false); cleanup(); };
                    const onConfirm = () => { closeModal(); resolve(true); cleanup(); };
                    const onOverlay = (e) => { if (e.target === modal) { closeModal(); resolve(false); cleanup(); } };

                    function cleanup() {
                        document.getElementById('modal-cancel').removeEventListener('click', onCancel);
                        document.getElementById('modal-confirm').removeEventListener('click', onConfirm);
                        modal.removeEventListener('click', onOverlay);
                    }

                    document.getElementById('modal-cancel').addEventListener('click', onCancel);
                    document.getElementById('modal-confirm').addEventListener('click', onConfirm);
                    modal.addEventListener('click', onOverlay);
                });
            }

            async function showAlert(title, message) {
                return new Promise((resolve) => {
                    modalIcon.textContent = 'i';
                    modalIcon.style.background = 'rgba(8, 145, 178, 0.12)';
                    modalIcon.style.color = '#0891b2';
                    modalTitle.textContent = title;
                    modalMsg.textContent = message;

                    modalActions.innerHTML = `
                        <button class="btn btn-sm btn-modal-ok" id="modal-ok">OK</button>
                    `;

                    openModal();

                    const onOk = () => { closeModal(); resolve(); cleanup(); };
                    const onOverlay = (e) => { if (e.target === modal) { closeModal(); resolve(); cleanup(); } };

                    function cleanup() {
                        document.getElementById('modal-ok').removeEventListener('click', onOk);
                        modal.removeEventListener('click', onOverlay);
                    }

                    document.getElementById('modal-ok').addEventListener('click', onOk);
                    modal.addEventListener('click', onOverlay);
                });
            }

            <?php if ($isLoggedIn): ?>
                document.getElementById('logout-button')?.addEventListener('click', async () => {
                    await fetch('/api/register', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'logout'
                        })
                    });
                    document.cookie = 'remembered_phone=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/';
                    window.location.href = '/';
                });
            <?php endif; ?>

            // Cookie consent banner
            (function () {
                var banner = document.getElementById('cookie-banner');
                var acceptBtn = document.getElementById('cookie-accept');
                if (!banner || !acceptBtn) return;
                if (document.cookie.split('; ').some(function (c) { return c.indexOf('cookie_consent=') === 0; })) {
                    return;
                }
                banner.classList.add('cookie-banner-visible');
                acceptBtn.addEventListener('click', function () {
                    var d = new Date();
                    d.setTime(d.getTime() + 365 * 86400000);
                    document.cookie = 'cookie_consent=accepted;expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
                    banner.classList.remove('cookie-banner-visible');
                });
            })();
        </script>
    </body>

    </html>
<?php
}

function maskMobileNumber(string $mobile): string
{
    $mobile = trim($mobile);
    if ($mobile === '') {
        return 'your account';
    }

    $visibleStart = str_starts_with($mobile, '+') ? 3 : 2;
    $start = substr($mobile, 0, $visibleStart);
    $end = substr($mobile, -3);

    return $start . '...' . $end;
}

function renderSmsMessage(string $message): string
{
    $escaped = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $linked = preg_replace_callback(
        '~(https?://[^\s<]+|/preferences\?token=[A-Za-z0-9]+)~',
        static function (array $matches): string {
            $url = $matches[1];
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">Visit preference page</a>';
        },
        $escaped
    );

    return nl2br($linked ?? $escaped);
}

function getDashboardSummary(mysqli $db, int $userId): array
{
    ensurePingDashboardColumns($db);

    $pings = [];
    $stmt = $db->prepare(
        "SELECT p.id, p.created_at, p.fingerprint_self, p.fingerprint_target, p.target_masked, p.recipient_name,
                m.id AS match_id, m.status AS match_status, m.stage, m.completed_at,
                m.user_a_id, m.user_b_id,
                mt.token, mt.is_used, mt.expires_at,
                mp.id AS pref_id,
                CASE
                    WHEN m.id IS NULL THEN 'pending'
                    WHEN m.completed_at IS NOT NULL OR m.status = 'completed' THEN 'completed'
                    ELSE 'matched'
                END AS ping_status
         FROM pings p
         LEFT JOIN matches m
           ON (
               (m.fingerprint_a = p.fingerprint_self AND m.fingerprint_b = p.fingerprint_target)
               OR (m.fingerprint_a = p.fingerprint_target AND m.fingerprint_b = p.fingerprint_self)
           )
         LEFT JOIN match_tokens mt
           ON mt.match_id = m.id AND mt.user_id = p.user_id AND mt.is_used = 0 AND mt.expires_at > NOW()
         LEFT JOIN match_preferences mp
           ON mp.match_id = m.id AND mp.user_id = p.user_id
         WHERE p.user_id = ?
         ORDER BY p.created_at DESC"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $created = new DateTime((string) $row['created_at']);
        $expires = (clone $created)->modify('+30 days');
        $now = new DateTime();
        $daysLeft = $now > $expires ? 0 : min(30, max(1, (int) $now->diff($expires)->format('%a') + 1));
        $row['days_until_expiry'] = $daysLeft;
        $pings[] = $row;
    }
    $stmt->close();

    $counts = ['pending' => 0, 'matched' => 0, 'completed' => 0];
    foreach ($pings as $ping) {
        $status = $ping['ping_status'] ?? 'pending';
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
    }

    return [
        'pings' => $pings,
        'counts' => $counts,
    ];
}

function ensurePingDashboardColumns(mysqli $db): void
{
    ensureColumnExists($db, 'pings', 'target_masked', "ALTER TABLE pings ADD COLUMN target_masked VARCHAR(40) NULL AFTER fingerprint_target");
    ensureColumnExists($db, 'pings', 'recipient_name', "ALTER TABLE pings ADD COLUMN recipient_name VARCHAR(120) NULL AFTER target_masked");
}

function ensureLoginAttemptsTable(mysqli $db): void
{
    $dbName = $db->query('SELECT DATABASE() AS db')->fetch_assoc()['db'] ?? '';
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'login_attempts'"
    );
    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $exists = ((int) $stmt->get_result()->fetch_assoc()['cnt']) > 0;
    $stmt->close();

    if (!$exists) {
        $db->query("
            CREATE TABLE login_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                contact_hash CHAR(64) NOT NULL,
                attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_login_attempts_hash (contact_hash),
                INDEX idx_login_attempts_time (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

function ensureColumnExists(mysqli $db, string $table, string $column, string $alterSql): void
{
    $database = $db->query('SELECT DATABASE() AS db_name')->fetch_assoc()['db_name'] ?? '';
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS column_count
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('sss', $database, $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $exists = ((int) ($row['column_count'] ?? 0)) > 0;
    $stmt->close();

    if (!$exists) {
        $db->query($alterSql);
    }
}

// API Routes
if (strpos($_SERVER['REQUEST_URI'], '/api/') === 0) {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

    if ($method !== 'GET' && $method !== 'POST') {
        Response::json(['error' => 'Not found.'], 404);
        exit;
    }


    if ($path === '/api/register') {
        try {
            $userController = new UserController($userService, $smsService);
            $userController->handle();
        } catch (RuntimeException $e) {
            Response::json(['error' => $e->getMessage()], 429);
        }
        exit;
    }

    if ($path === '/api/ping') {
        try {
            $pingController = new PingController($peacePingService, $rateLimiter, $userService);
            $pingController->handle($_SERVER['REMOTE_ADDR']);
        } catch (Exception $e) {
            error_log('Ping controller error: ' . $e->getMessage());
            Response::json(['error' => 'Internal server error.'], 500);
        }
        exit;
    }

    if ($path === '/api/delete-ping') {
        $currentUser = $userService->getCurrentUser();
        if ($currentUser === null) {
            Response::json(['error' => 'Not authenticated.'], 401);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $pingId = isset($input['ping_id']) ? (int) $input['ping_id'] : 0;

        if ($pingId <= 0) {
            Response::json(['error' => 'Invalid ping ID.'], 400);
            exit;
        }

        $deleted = $peacePingService->deletePing($pingId, (int) $currentUser['id']);
        if ($deleted) {
            $summary = getDashboardSummary($db, (int) $currentUser['id']);
            Response::json([
                'success' => true,
                'message' => 'Peace Ping cancelled.',
                'counts' => $summary['counts']
            ]);
        } else {
            Response::json(['error' => 'Could not delete ping. It may not exist or may not belong to you.'], 400);
        }
        exit;
    }

    // 404 for API
    Response::json(['error' => 'API endpoint not found.'], 404);
}

// Page Routes
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$isPreferencesRoute = $path === '/preferences' || strpos($path, '/preferences/') === 0;

if ($method !== 'GET' && !($isPreferencesRoute && $method === 'POST')) {
    Response::json(['error' => 'Not found.'], 404);
    exit;
}
// Homepage
if ($path === '/' || $path === '/home') {
    ob_start();
?>
    <div class="homepage">
        <section class="hero">
            <div class="hero-content">
                <p class="kicker">Peace Ping</p>
                <h1>Reconnect Without Pressure</h1>
                <p>
                    Sometimes you want to reconnect, but you're not sure if the other person feels the same.
                    Peace Ping makes it safe - both people need to express interest before any contact happens.
                </p>
                <div class="hero-actions">
                    <a href="/register" class="btn">Get Started</a>
                    <a href="/how-it-works" class="btn btn-secondary">Learn More</a>
                </div>
            </div>
        </section>

        <section class="features">
            <h2>How Peace Ping Works</h2>
            <div class="feature-grid">
                <div class="feature">
                    <h3><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c-4 0-7 3-7 7v7l4-3c2 1 4 1 6 0l4 3v-7c0-4-3-7-7-7z"/><line x1="9" y1="10" x2="15" y2="10"/></svg> Private Interest</h3>
                    <p>Send a Peace Ping to someone you're thinking about. They won't know unless they also ping you back.</p>
                </div>
                <div class="feature">
                    <h3><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l-2 2c-1 1-1 2 0 3l2 2c1 1 2 1 3 0l2-2"/><path d="M18 15l2 2c1 1 1 2 0 3l-2 2c-1 1-2 1-3 0l-2-2"/><path d="M8 7l-3 3 5 5 3-3"/><path d="M16 7l3 3-5 5-3-3"/><path d="M12 4v3"/><path d="M9 7h6"/></svg> Both Say Yes</h3>
                    <p>Only when both people ping each other does a connection happen. No awkward conversations!</p>
                </div>
                <div class="feature">
                    <h3><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><circle cx="12" cy="16" r="1.5"/><path d="M8 11V7a4 4 0 018 0v4"/></svg> Privacy First</h3>
                    <p>Phone numbers you enter to find someone are turned into secret codes and never saved.</p>
                </div>
            </div>
        </section>
    </div>
<?php
    $content = ob_get_clean();
    renderPage('Peace Ping', $content, 'home');
    exit;
}

// How It Works page
if ($path === '/how-it-works') {
    ob_start();
?>
    <div class="how-it-works">
        <div class="page-header">
            <h1>How Peace Ping Works</h1>
            <p>Simple, thoughtful reconnection without the awkwardness of reaching out directly.</p>
        </div>

        <div class="steps-grid hiw-steps">
            <div class="step">
                <div class="step-number">1</div>
                <div class="step-content">
                    <h3>Send Your Peace Ping</h3>
                    <p>Think of someone you'd like to reconnect with and send them a Peace Ping using their phone number.</p>
                </div>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <div class="step-content">
                    <h3>Wait for a Match</h3>
                    <p>If they also send you a Peace Ping, we let both of you know you have a match.</p>
                </div>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <div class="step-content">
                    <h3>Share Your Preferences</h3>
                    <p>Both people receive private links to say how comfortable they feel about reconnecting.</p>
                </div>
            </div>
            <div class="step">
                <div class="step-number">4</div>
                <div class="step-content">
                    <h3>Reconnect Thoughtfully</h3>
                    <p>Based on what both people prefer, you'll get helpful guidance on how to reconnect.</p>
                </div>
            </div>
        </div>

        <div class="privacy-simple">
            <h2><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><circle cx="12" cy="16" r="1.5"/><path d="M8 11V7a4 4 0 018 0v4"/></svg> Your Privacy Matters</h2>
            <div class="privacy-grid">
                <div class="privacy-item">
                    <h3>Private Matching</h3>
                    <p>Phone numbers are turned into secret codes before any matching happens.</p>
                </div>
                <div class="privacy-item">
                    <h3>Numbers Never Saved</h3>
                    <p>The numbers you enter to find someone are turned into secret codes and never stored.</p>
                </div>
                <div class="privacy-item">
                    <h3>Your Account is Locked</h3>
                    <p>Your own mobile number is protected so only you can access your account. It is only used to send you SMS messages.</p>
                </div>
                <div class="privacy-item">
                    <h3>Both Agree or Nothing</h3>
                    <p>Nobody is told you are interested unless they also send you a Peace Ping.</p>
                </div>
                <div class="privacy-item">
                    <h3>You Stay in Control</h3>
                    <p>You decide if and when to reconnect. Your preferences are never shared with anyone.</p>
                </div>
                <div class="privacy-item">
                    <h3>No Unwanted Contact</h3>
                    <p>We never share your contact details with anyone without your permission.</p>
                </div>
            </div>
        </div>
    </div>
<?php
    $content = ob_get_clean();
    renderPage('How It Works', $content, 'how-it-works');
    exit;
}

// Register page
if ($path === '/register') {
    $currentUser = $userService->getCurrentUser();
    if ($currentUser !== null) {
        header('Location: /dashboard');
        exit;
    }

    ob_start();
?>
    <div class="register-page">
        <div class="page-header">
            <h1>Create Your Peace Ping Account</h1>
            <p>Register with your mobile number to start reconnecting</p>
        </div>

        <section class="grid">
            <article class="card">
                <h2><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg> Register Your Account</h2>
                <form id="register-form">
                    <div class="form-group">
                        <label for="register-phone">Mobile Number</label>
                        <input type="tel" id="register-phone" name="phone" placeholder="07xxx xxxxxx or +447xxx xxxxxx" autocomplete="tel" inputmode="tel" required>
                        <small>Use a UK mobile (07xxx xxxxxx) or international (+44123...). Spaces and dashes are fine - we fix the format for you.</small>
                    </div>
                    <div class="form-group">
                        <label for="register-phone-confirm">Confirm Mobile Number</label>
                        <input type="tel" id="register-phone-confirm" name="confirm_phone" placeholder="Re-enter your mobile number" autocomplete="tel" inputmode="tel" required>
                        <p class="field-error" id="register-phone-confirm-error" aria-live="polite"></p>
                    </div>
                    <div class="form-group">
                        <label for="register-password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="register-password" name="password" placeholder="Create a password" autocomplete="new-password" required minlength="8">
                            <button type="button" class="password-toggle" data-target="register-password" aria-label="Show password">
                                <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" fill="currentColor"/></svg>
                                <svg class="eye-slash-icon" viewBox="0 0 24 24" width="20" height="20" style="display:none"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z" fill="currentColor"/></svg>
                            </button>
                        </div>
                        <small>Password must be at least 8 characters long and include at least one uppercase letter and one number.</small>
                    </div>
                    <div class="password-strength">
                        <div class="strength-label">Password strength</div>
                        <div class="strength-bar">
                            <span id="password-strength-fill"></span>
                        </div>
                        <div id="password-strength-text" class="strength-text">Minimum 8 characters, one capital letter, and one number.</div>
                    </div>
                    <div class="form-group">
                        <label for="register-password-confirm">Confirm Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="register-password-confirm" name="confirm_password" placeholder="Re-enter your password" autocomplete="new-password" required minlength="8">
                            <button type="button" class="password-toggle" data-target="register-password-confirm" aria-label="Show password">
                                <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" fill="currentColor"/></svg>
                                <svg class="eye-slash-icon" viewBox="0 0 24 24" width="20" height="20" style="display:none"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z" fill="currentColor"/></svg>
                            </button>
                        </div>
                        <p class="field-error" id="register-password-confirm-error" aria-live="polite"></p>
                    </div>
                    <button type="submit" class="btn">Send Verification Code</button>
                </form>
                <p id="register-result" class="result" aria-live="polite"></p>

                <form id="verify-form" style="display: none;">
                    <div class="form-group">
                        <label for="verify-code">Verification Code</label>
                        <input id="verify-code" name="code" type="text" required placeholder="123456" maxlength="6" inputmode="numeric">
                    </div>
                    <button type="submit" class="btn">Verify & Create Account</button>
                </form>
                <p id="verify-result" class="result" aria-live="polite"></p>
            </article>

            <article class="card">
                <h2><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><circle cx="12" cy="16" r="1.5"/><path d="M8 11V7a4 4 0 018 0v4"/><path d="M17 4l-4 4"/><path d="M19 2l-2 2"/></svg> Already Registered?</h2>
                <p>If you already have an account, log in with your mobile number and password.</p>
                <div style="margin-top: var(--space-lg);">
                    <a href="/login" class="btn btn-secondary">Log In</a>
                </div>
            </article>
        </section>
    </div>

    <script>
        async function postJson(url, payload) {
            const response = await fetch(url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify(payload)
            });

            const body = await response.json().catch(() => ({}));
            return {
                ok: response.ok,
                status: response.status,
                body
            };
        }

        function showResult(target, text, tone) {
            if (!text || text.trim() === '') {
                target.style.display = 'none';
                return;
            }

            target.style.display = 'block';
            target.textContent = text;
            target.classList.remove("ok", "warn");
            if (tone) {
                target.classList.add(tone);
            }
        }

        // Registration form handler
        const registerForm = document.getElementById("register-form");
        const verifyForm = document.getElementById("verify-form");
        const registerResult = document.getElementById("register-result");
        const verifyResult = document.getElementById("verify-result");
        const registerPhone = document.getElementById("register-phone");
        const registerPhoneConfirm = document.getElementById("register-phone-confirm");
        const registerPassword = document.getElementById("register-password");
        const registerPasswordConfirm = document.getElementById("register-password-confirm");
        const registerPhoneConfirmError = document.getElementById("register-phone-confirm-error");
        const registerPasswordConfirmError = document.getElementById("register-password-confirm-error");
        const passwordStrengthFill = document.getElementById("password-strength-fill");
        const passwordStrengthText = document.getElementById("password-strength-text");
        const submitButton = registerForm.querySelector('button[type="submit"]');

        const normalizePhone = (value) => {
            const trimmed = value.trim();
            const digits = trimmed.replace(/\D/g, '');
            if (trimmed.startsWith('+')) return '+' + digits;
            if (digits.startsWith('00')) return '+' + digits.slice(2);
            if (digits.startsWith('0')) return '+44' + digits.slice(1);
            return '+' + digits;
        };

        const getPasswordStrengthScore = (password) => {
            let score = 0;
            if (password.length >= 8) score += 1;
            if (/[A-Z]/.test(password)) score += 1;
            if (/[0-9]/.test(password)) score += 1;
            if (/[^A-Za-z0-9]/.test(password)) score += 1;
            return score;
        };

        const getStrengthLabel = (score) => {
            if (score <= 1) return 'Weak';
            if (score === 2) return 'Fair';
            if (score === 3) return 'Good';
            return 'Strong';
        };

        const updatePasswordStrength = () => {
            const password = registerPassword.value;
            const score = getPasswordStrengthScore(password);
            const label = getStrengthLabel(score);
            const width = (score / 4) * 100;

            if (passwordStrengthFill) {
                passwordStrengthFill.style.width = width + '%';
                passwordStrengthFill.className = '';
                passwordStrengthFill.classList.add(label.toLowerCase());
            }
            if (passwordStrengthText) {
                passwordStrengthText.textContent = `${label} password.`;
            }
        };

        const getRegisterValidationMessage = () => {
            const phone = registerPhone.value.trim();
            const confirmPhone = registerPhoneConfirm.value.trim();
            const password = registerPassword.value;
            const confirmPassword = registerPasswordConfirm.value;

            if (password) {
                if (password.length < 8) {
                    return 'Password must be at least 8 characters long.';
                }
                if (!/[A-Z]/.test(password) || !/[0-9]/.test(password)) {
                    return 'Password must contain at least one uppercase letter and one number.';
                }
            }

            return '';
        };

        const updateRegisterValidation = () => {
            const phone = registerPhone.value.trim();
            const confirmPhone = registerPhoneConfirm.value.trim();
            const password = registerPassword.value;
            const confirmPassword = registerPasswordConfirm.value;

            let phoneError = '';
            let passwordError = '';

            if (phone && confirmPhone && normalizePhone(phone) !== normalizePhone(confirmPhone)) {
                phoneError = 'The mobile numbers do not match.';
            }

            if (password && confirmPassword && password !== confirmPassword) {
                passwordError = 'The passwords do not match.';
            }

            registerPhoneConfirmError.textContent = phoneError;
            registerPasswordConfirmError.textContent = passwordError;

            const message = getRegisterValidationMessage();
            submitButton.disabled = !!(message || phoneError || passwordError);
        };

        registerPhone.addEventListener('input', updateRegisterValidation);
        registerPhoneConfirm.addEventListener('input', updateRegisterValidation);
        registerPassword.addEventListener('input', () => {
            updatePasswordStrength();
            updateRegisterValidation();
        });
        registerPasswordConfirm.addEventListener('input', updateRegisterValidation);

        registerForm.addEventListener("submit", async (event) => {
            event.preventDefault();

            const phone = registerPhone.value.trim();
            const confirmPhone = registerPhoneConfirm.value.trim();
            const password = registerPassword.value;
            const confirmPassword = registerPasswordConfirm.value;
            const validationMessage = getRegisterValidationMessage();

            if (validationMessage) {
                showResult(registerResult, validationMessage, 'warn');
                return;
            }

            if (phone === '') {
                showResult(registerResult, "Please enter your mobile number.", "warn");
                return;
            }

            if (confirmPhone === '') {
                showResult(registerResult, "Please confirm your mobile number.", "warn");
                return;
            }

            if (password === '') {
                showResult(registerResult, "Please enter a password.", "warn");
                return;
            }

            if (confirmPassword === '') {
                showResult(registerResult, "Please confirm your password.", "warn");
                return;
            }

            showResult(registerResult, "Sending verification code...", null);

            const response = await postJson("api/register", {
                action: 'register',
                phone: phone,
                confirm_phone: confirmPhone,
                password: password,
                confirm_password: confirmPassword
            });

            if (!response.ok) {
                const err = response.body.error || "Registration failed.";
                showResult(registerResult, err, "warn");
                return;
            }

            showResult(registerResult, response.body.message, "ok");
            registerForm.style.display = "none";
            verifyForm.style.display = "block";
        });

        verifyForm.addEventListener("submit", async (event) => {
            event.preventDefault();

            const code = document.getElementById("verify-code").value.trim();

            if (code === '') {
                showResult(verifyResult, "Please enter verification code.", "warn");
                return;
            }

            showResult(verifyResult, "Verifying...", null);

            const response = await postJson("api/register", {
                action: 'verify',
                code: code
            });

            if (!response.ok) {
                const err = response.body.error || "Verification failed.";
                showResult(verifyResult, err, "warn");
                return;
            }

            showResult(verifyResult, "Account verified. Opening your dashboard...", "ok");
            setTimeout(() => {
                window.location.href = '/dashboard';
            }, 800);
        });
    </script>
<?php
    $content = ob_get_clean();
    renderPage('Register & Verify', $content, 'register');
    exit;
}

// Login page
if ($path === '/login') {
    $currentUser = $userService->getCurrentUser();
    if ($currentUser !== null) {
        header('Location: /dashboard');
        exit;
    }

    ob_start();
?>
    <div class="login-page">
        <div class="page-header">
            <h1>Log In to Peace Ping</h1>
            <p>Use your registered mobile number and password.</p>
        </div>

        <section class="grid">
            <article class="card">
                <h2>Log In</h2>
                <form id="login-form" novalidate>
                    <div class="form-group" id="phone-remembered-group" style="display:none">
                        <label>Mobile Number</label>
                        <p class="remembered-phone-text" id="phone-display"></p>
                        <small>Not you? <a href="#" id="clear-remembered-phone">Use a different number</a></small>
                    </div>
                    <div class="form-group" id="phone-input-group">
                        <label for="login-phone">Mobile Number</label>
                        <input type="tel" id="login-phone" name="phone" placeholder="07xxx xxxxxx or +447xxx xxxxxx" autocomplete="tel" inputmode="tel">
                        <small>Enter the mobile number you used when registering.</small>
                    </div>
                    <div class="form-group">
                        <label for="login-password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="login-password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                            <button type="button" class="password-toggle" data-target="login-password" aria-label="Show password">
                                <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" fill="currentColor"/></svg>
                                <svg class="eye-slash-icon" viewBox="0 0 24 24" width="20" height="20" style="display:none"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z" fill="currentColor"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="forgot-link">
                        <a href="/forgot-password">Forgot password?</a>
                    </div>
                    <button type="submit" class="btn">Log In</button>
                </form>
                <p id="login-lockout-msg" class="result warn" style="display:none"></p>
                <p id="login-result" class="result" aria-live="polite"></p>
            </article>

            <article class="card">
                <h2>New Here?</h2>
                <p>Create an account first, then you can return here whenever you need to sign back in.</p>
                <div style="margin-top: var(--space-lg);">
                    <a href="/register" class="btn btn-secondary">Register</a>
                </div>
            </article>
        </section>
    </div>

    <script>
        async function postJson(url, payload) {
            const response = await fetch(url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify(payload)
            });

            const body = await response.json().catch(() => ({}));
            return {
                ok: response.ok,
                status: response.status,
                body
            };
        }

        function showResult(target, text, tone) {
            if (!text || text.trim() === '') {
                target.style.display = 'none';
                return;
            }

            target.style.display = 'block';
            target.textContent = text;
            target.classList.remove("ok", "warn");
            if (tone) {
                target.classList.add(tone);
            }
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }

        function getCookie(name) {
            const match = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
            return match ? decodeURIComponent(match[2]) : null;
        }

        function setCookie(name, value, days) {
            const d = new Date();
            d.setTime(d.getTime() + days * 86400000);
            document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
        }

        function eraseCookie(name) {
            document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/';
        }

        const loginForm = document.getElementById('login-form');
        const loginResult = document.getElementById('login-result');
        const phoneInputGroup = document.getElementById('phone-input-group');
        const phoneRememberedGroup = document.getElementById('phone-remembered-group');
        const phoneDisplay = document.getElementById('phone-display');
        const clearPhoneLink = document.getElementById('clear-remembered-phone');

        let savedPhone = getCookie('remembered_phone');

        function maskPhone(phone) {
            var digits = phone.replace(/\D/g, '');
            if (digits.length < 6) return phone;
            var start = digits.slice(0, 4);
            var end = digits.slice(-3);
            return start + '***' + end;
        }

        function showPhoneInput() {
            phoneRememberedGroup.style.display = 'none';
            phoneInputGroup.style.display = 'block';
            document.getElementById('login-phone').value = '';
        }

        function showRememberedPhone(phone) {
            phoneDisplay.textContent = maskPhone(phone);
            phoneRememberedGroup.style.display = 'block';
            phoneInputGroup.style.display = 'none';
        }

        if (savedPhone) {
            showRememberedPhone(savedPhone);
        }

        if (clearPhoneLink) {
            clearPhoneLink.addEventListener('click', function (e) {
                e.preventDefault();
                savedPhone = null;
                eraseCookie('remembered_phone');
                showPhoneInput();
                document.getElementById('login-phone').focus();
            });
        }

        if (loginForm) {
            loginForm.addEventListener('submit', async (event) => {
                event.preventDefault();

                try {
                    const phone = savedPhone || document.getElementById('login-phone').value.trim();
                    const password = document.getElementById('login-password').value;

                    if (!phone) {
                        showResult(loginResult, 'Please enter your mobile number.', 'warn');
                        return;
                    }

                    if (!password) {
                        showResult(loginResult, 'Please enter your password.', 'warn');
                        return;
                    }

                    showResult(loginResult, 'Logging in...', null);
                    const response = await postJson('/api/register', {
                        action: 'login',
                        phone: phone,
                        password: password
                    });

                    if (!response.ok) {
                        showResult(loginResult, response.body.error || 'Login failed.', 'warn');

                        if (response.body.lockout_remaining) {
                            var lockoutEnd = Date.now() + (response.body.lockout_remaining * 1000);
                            setCookie('login_lockout_end', lockoutEnd.toString(), 1);
                            startLockoutCountdown(lockoutEnd);
                        }
                        return;
                    }

                    setCookie('remembered_phone', phone, 365);
                    eraseCookie('login_lockout_end');

                    showResult(loginResult, response.body.message, 'ok');
                    window.location.href = '/dashboard';
                } catch (err) {
                    showResult(loginResult, 'Something went wrong. Please try again.', 'warn');
                }
            });
        }

        // Lockout countdown display
        var lockoutDisplay = document.getElementById('login-lockout-msg');
        var lockoutTimer = null;

        function startLockoutCountdown(endTime) {
            var loginBtn = loginForm.querySelector('button[type="submit"]');
            var phoneInput = document.getElementById('login-phone');
            var passwordInput = document.getElementById('login-password');

            function tick() {
                var remaining = Math.max(0, Math.floor((endTime - Date.now()) / 1000));
                if (remaining <= 0) {
                    lockoutDisplay.style.display = 'none';
                    loginBtn.disabled = false;
                    phoneInput.disabled = false;
                    passwordInput.disabled = false;
                    loginBtn.textContent = 'Log In';
                    clearInterval(lockoutTimer);
                    eraseCookie('login_lockout_end');
                    return;
                }
                var mins = Math.floor(remaining / 60);
                var secs = remaining % 60;
                lockoutDisplay.textContent = 'Too many attempts. Try again in ' + mins + 'm ' + (secs < 10 ? '0' : '') + secs + 's.';
                lockoutDisplay.style.display = 'block';
                loginBtn.disabled = true;
                phoneInput.disabled = true;
                passwordInput.disabled = true;
                loginBtn.textContent = 'Locked';
            }

            tick();
            lockoutTimer = setInterval(tick, 1000);
        }

        // Check for existing lockout cookie on page load
        (function () {
            var cookieVal = getCookie('login_lockout_end');
            if (cookieVal) {
                var endTime = parseInt(cookieVal, 10);
                // Sanity check: ignore if >16 minutes ahead (corrupted cookie from old bug)
                var maxValid = Date.now() + 16 * 60 * 1000;
                if (!isNaN(endTime) && endTime > Date.now() && endTime <= maxValid) {
                    startLockoutCountdown(endTime);
                } else {
                    eraseCookie('login_lockout_end');
                }
            }
        })();

        // Test helper: ?save=PHONE sets a remembered phone cookie without logging in
        (function () {
            var params = new URLSearchParams(window.location.search);
            var testPhone = params.get('save');
            if (testPhone) {
                setCookie('remembered_phone', testPhone, 365);
                window.location.href = '/login';
            }
        })();
    </script>
<?php
    $content = ob_get_clean();
    renderPage('Login', $content, 'login');
    exit;
}

// Forgot Password page
if ($path === '/forgot-password') {
    ob_start();
?>
    <div class="forgot-page">
        <div class="page-header">
            <h1>Reset Your Password</h1>
            <p>Enter your mobile number to receive a verification code.</p>
        </div>

        <section class="card forgot-card">
            <h2><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg> Verify Your Identity</h2>
            <form id="forgot-form">
                <div class="form-group">
                    <label for="forgot-phone">Mobile Number</label>
                    <input type="tel" id="forgot-phone" name="phone" placeholder="07xxx xxxxxx or +447xxx xxxxxx" autocomplete="tel" inputmode="tel" required>
                    <small>Enter the mobile number you used when registering.</small>
                </div>
                <button type="submit" class="btn">Send Verification Code</button>
            </form>
            <p id="forgot-result" class="result" aria-live="polite"></p>

            <form id="forgot-verify-form" style="display: none;">
                <div class="form-group">
                    <label for="forgot-code">Verification Code</label>
                    <input id="forgot-code" name="code" type="text" required placeholder="123456" maxlength="6" inputmode="numeric">
                </div>
                <button type="submit" class="btn">Verify Code</button>
                <p class="resend-note" id="forgot-resend-note" style="display:none;">You can request a new code if needed.</p>
                <button type="button" class="btn btn-secondary" id="forgot-resend-btn" style="display:none;">Resend Code</button>
            </form>
            <p id="forgot-verify-result" class="result" aria-live="polite"></p>
            <p class="forgot-back-link"><a href="/login">Back to Login</a></p>
        </section>
    </div>

    <script>
        async function postJson(url, payload) {
            const response = await fetch(url, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
            const body = await response.json().catch(() => ({}));
            return { ok: response.ok, status: response.status, body };
        }
        function showResult(target, text, tone) {
            if (!text || text.trim() === '') { target.style.display = 'none'; return; }
            target.style.display = 'block';
            target.textContent = text;
            target.classList.remove("ok", "warn");
            if (tone) target.classList.add(tone);
        }

        const forgotForm = document.getElementById('forgot-form');
        const forgotVerifyForm = document.getElementById('forgot-verify-form');
        const forgotResult = document.getElementById('forgot-result');
        const forgotVerifyResult = document.getElementById('forgot-verify-result');
        const forgotResendBtn = document.getElementById('forgot-resend-btn');
        const forgotResendNote = document.getElementById('forgot-resend-note');
        let resendCount = 0;
        let verifiedPhone = '';

        forgotForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const phone = document.getElementById('forgot-phone').value.trim();
            if (!phone) {
                showResult(forgotResult, 'Please enter your mobile number.', 'warn');
                return;
            }
            showResult(forgotResult, 'Sending verification code...', null);
            const res = await postJson('api/register', { action: 'send_reset_otp', phone });
            if (!res.ok) {
                showResult(forgotResult, res.body.error || 'Failed to send code.', 'warn');
                return;
            }
            verifiedPhone = phone;
            showResult(forgotResult, res.body.message, 'ok');
            forgotForm.style.display = 'none';
            forgotVerifyForm.style.display = 'block';
            resendCount = 0;
            forgotResendBtn.style.display = 'none';
            forgotResendNote.style.display = 'none';
        });

        forgotResendBtn.addEventListener('click', async () => {
            if (resendCount >= 2) {
                showResult(forgotVerifyResult, 'Maximum resend attempts reached. Please start again.', 'warn');
                forgotResendBtn.style.display = 'none';
                return;
            }
            forgotResendBtn.disabled = true;
            const res = await postJson('api/register', { action: 'send_reset_otp', phone: verifiedPhone });
            if (res.ok) {
                resendCount++;
                showResult(forgotVerifyResult, 'New code sent.', 'ok');
                if (resendCount >= 2) {
                    forgotResendBtn.style.display = 'none';
                }
            } else {
                showResult(forgotVerifyResult, res.body.error || 'Failed to resend code.', 'warn');
            }
            forgotResendBtn.disabled = false;
        });

        forgotVerifyForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const code = document.getElementById('forgot-code').value.trim();
            if (!code) {
                showResult(forgotVerifyResult, 'Please enter the verification code.', 'warn');
                return;
            }
            showResult(forgotVerifyResult, 'Verifying...', null);
            const res = await postJson('api/register', { action: 'verify_reset_otp', phone: verifiedPhone, code });
            if (!res.ok) {
                showResult(forgotVerifyResult, res.body.error || 'Invalid code.', 'warn');
                if (resendCount < 2) {
                    forgotResendBtn.style.display = 'inline-block';
                    forgotResendNote.style.display = 'block';
                }
                return;
            }
            showResult(forgotVerifyResult, 'Verified! Redirecting...', 'ok');
            setTimeout(() => {
                window.location.href = '/reset-password?phone=' + encodeURIComponent(verifiedPhone);
            }, 800);
        });
    </script>
<?php
    $content = ob_get_clean();
    renderPage('Forgot Password', $content, 'forgot-password');
    exit;
}

// Reset Password page
if ($path === '/reset-password') {
    $phone = $_GET['phone'] ?? '';
    if ($phone === '') {
        header('Location: /forgot-password');
        exit;
    }

    ob_start();
?>
    <div class="reset-page">
        <div class="page-header">
            <h1>Create New Password</h1>
            <p>Choose a new password for your account.</p>
        </div>

        <section class="card reset-card">
            <h2><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><circle cx="12" cy="16" r="1.5"/><path d="M8 11V7a4 4 0 018 0v4"/><path d="M17 4l-4 4"/><path d="M19 2l-2 2"/></svg> New Password</h2>
            <form id="reset-form">
                <div class="form-group">
                    <label for="reset-password">New Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="reset-password" name="password" placeholder="Create a new password" autocomplete="new-password" required minlength="8">
                        <button type="button" class="password-toggle" data-target="reset-password" aria-label="Show password">
                            <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" fill="currentColor"/></svg>
                            <svg class="eye-slash-icon" viewBox="0 0 24 24" width="20" height="20" style="display:none"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z" fill="currentColor"/></svg>
                            </button>
                        </div>
                    <small>Password must be at least 8 characters long and include at least one uppercase letter and one number.</small>
                </div>
                <div class="password-strength">
                    <div class="strength-label">Password strength</div>
                    <div class="strength-bar">
                        <span id="reset-password-strength-fill"></span>
                    </div>
                    <div id="reset-password-strength-text" class="strength-text">Minimum 8 characters, one capital letter, and one number.</div>
                </div>
                <div class="form-group">
                    <label for="reset-password-confirm">Confirm New Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="reset-password-confirm" name="confirm_password" placeholder="Re-enter your new password" autocomplete="new-password" required minlength="8">
                        <button type="button" class="password-toggle" data-target="reset-password-confirm" aria-label="Show password">
                            <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" fill="currentColor"/></svg>
                            <svg class="eye-slash-icon" viewBox="0 0 24 24" width="20" height="20" style="display:none"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z" fill="currentColor"/></svg>
                            </button>
                        </div>
                    <p class="field-error" id="reset-password-confirm-error" aria-live="polite"></p>
                </div>
                <button type="submit" class="btn">Reset Password</button>
            </form>
            <p id="reset-result" class="result" aria-live="polite"></p>
            <p class="forgot-back-link"><a href="/login">Back to Login</a></p>
        </section>
    </div>

    <script>
        async function postJson(url, payload) {
            const response = await fetch(url, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
            const body = await response.json().catch(() => ({}));
            return { ok: response.ok, status: response.status, body };
        }
        function showResult(target, text, tone) {
            if (!text || text.trim() === '') { target.style.display = 'none'; return; }
            target.style.display = 'block';
            target.textContent = text;
            target.classList.remove("ok", "warn");
            if (tone) target.classList.add(tone);
        }

        const resetForm = document.getElementById('reset-form');
        const resetResult = document.getElementById('reset-result');
        const resetPassword = document.getElementById('reset-password');
        const resetPasswordConfirm = document.getElementById('reset-password-confirm');
        const resetPassConfirmError = document.getElementById('reset-password-confirm-error');
        const resetStrengthFill = document.getElementById('reset-password-strength-fill');
        const resetStrengthText = document.getElementById('reset-password-strength-text');

        const getPasswordStrengthScore = (password) => {
            let score = 0;
            if (password.length >= 8) score += 1;
            if (/[A-Z]/.test(password)) score += 1;
            if (/[0-9]/.test(password)) score += 1;
            if (/[^A-Za-z0-9]/.test(password)) score += 1;
            return score;
        };

        const getStrengthLabel = (score) => {
            if (score <= 1) return 'Weak';
            if (score === 2) return 'Fair';
            if (score === 3) return 'Good';
            return 'Strong';
        };

        const updateResetStrength = () => {
            const password = resetPassword.value;
            const score = getPasswordStrengthScore(password);
            const label = getStrengthLabel(score);
            const width = (score / 4) * 100;
            if (resetStrengthFill) {
                resetStrengthFill.style.width = width + '%';
                resetStrengthFill.className = '';
                resetStrengthFill.classList.add(label.toLowerCase());
            }
            if (resetStrengthText) {
                resetStrengthText.textContent = label + ' password.';
            }
        };

        const updateResetValidation = () => {
            const pw = resetPassword.value;
            const cpw = resetPasswordConfirm.value;
            let err = '';
            if (pw && cpw && pw !== cpw) {
                err = 'The passwords do not match.';
            }
            if (resetPassConfirmError) resetPassConfirmError.textContent = err;
        };

        resetPassword.addEventListener('input', () => { updateResetStrength(); updateResetValidation(); });
        resetPasswordConfirm.addEventListener('input', updateResetValidation);

        resetForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const phone = new URLSearchParams(window.location.search).get('phone') || '';
            const password = resetPassword.value;
            const confirmPassword = resetPasswordConfirm.value;

            if (!password) { showResult(resetResult, 'Please enter a new password.', 'warn'); return; }
            if (password.length < 8) { showResult(resetResult, 'Password must be at least 8 characters.', 'warn'); return; }
            if (!/[A-Z]/.test(password) || !/[0-9]/.test(password)) { showResult(resetResult, 'Password must contain an uppercase letter and a number.', 'warn'); return; }
            if (!confirmPassword) { showResult(resetResult, 'Please confirm your password.', 'warn'); return; }
            if (password !== confirmPassword) { showResult(resetResult, 'Passwords do not match.', 'warn'); return; }

            showResult(resetResult, 'Resetting password...', null);
            const res = await postJson('api/register', { action: 'reset_password', phone, password, confirm_password: confirmPassword });
            if (!res.ok) {
                showResult(resetResult, res.body.error || 'Failed to reset password.', 'warn');
                return;
            }
            showResult(resetResult, 'Password reset successfully! Redirecting to login...', 'ok');
            setTimeout(() => { window.location.href = '/login'; }, 1200);
        });
    </script>
<?php
    $content = ob_get_clean();
    renderPage('Reset Password', $content, 'reset-password');
    exit;
}

// Ping page
if ($path === '/ping') {
    $currentUser = $userService->getCurrentUser();
    if ($currentUser === null) {
        header('Location: /login');
        exit;
    }

    ob_start();
?>
    <div class="ping-page">
        <div class="page-header">
            <h1>Send a Peace Ping</h1>
            <p>Take the first step towards reconnection</p>
        </div>

        <div class="ping-layout">
            <div class="ping-row-2col">
                <article class="card">
                    <h2><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Send Your Peace Ping</h2>
                    <form id="ping-form">
                        <div class="form-group">
                            <label for="recipient-name">Recipient Name (Optional)</label>
                            <input type="text" id="recipient-name" name="recipient_name" placeholder="Jane Doe" maxlength="120" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label for="ping-target">Mobile Number to Ping</label>
                            <input type="tel" id="ping-target" name="target" placeholder="07xxx xxxxxx or +447xxx xxxxxx" autocomplete="tel" inputmode="tel" required>
                            <p class="field-error" id="ping-target-error" aria-live="polite"></p>
                            <small>Enter a UK mobile (07xxx xxxxxx) or international number (+44123...). We fix the format for you.</small>
                        </div>
                        <div class="form-group">
                            <label for="ping-target-confirm">Confirm Recipient Number</label>
                            <input type="tel" id="ping-target-confirm" name="confirm_target" placeholder="Re-enter recipient number" autocomplete="tel" inputmode="tel" required>
                            <p class="field-error" id="ping-target-confirm-error" aria-live="polite"></p>
                        </div>
                        <button type="submit" class="btn">Send Peace Ping</button>
                    </form>
                    <p id="ping-result" class="result" aria-live="polite"></p>

                    <div id="match-info" hidden>
                        <h3><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v2"/><path d="M12 21v2"/><path d="M4.22 4.22l1.42 1.42"/><path d="M18.36 18.36l1.42 1.42"/><path d="M1 12h2"/><path d="M21 12h2"/><path d="M4.22 19.78l1.42-1.42"/><path d="M18.36 5.64l1.42-1.42"/></svg> Match Found!</h3>
                        <p id="match-message"></p>
                        <div id="next-steps">
                            <h4>What happens next?</h4>
                            <p>Both you and the other person will receive SMS messages with questions to help you reconnect comfortably.</p>
                        </div>
                    </div>
                </article>

                <article class="card">
                    <h2><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><circle cx="12" cy="16" r="1.5"/><path d="M8 11V7a4 4 0 018 0v4"/></svg> What We Store</h2>
                    <p>The phone numbers you enter are never saved. They are turned into secret codes that can only be used to detect when two people both want to reconnect. Even we cannot see the original numbers.</p>
                    <ul class="trust-list">
                        <li>No one is told you are interested unless they also send you a Peace Ping.</li>
                        <li>Phone numbers you enter are turned into secret codes and never stored.</li>
                        <li>Your privacy is protected at every step.</li>
                    </ul>
                </article>
            </div>

            <article class="card ping-how-full">
                <h2><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12" y2="17"/></svg> How It Works</h2>
                <div class="steps-grid">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h3>Send Your Peace Ping</h3>
                            <p>Think of someone you'd like to reconnect with and send them a Peace Ping using their phone number.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h3>Wait for a Match</h3>
                            <p>If they also send you a Peace Ping, we let you both know you have a match.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h3>Share Your Preferences</h3>
                            <p>Both people receive private links to say how comfortable they feel about reconnecting.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">4</div>
                        <div class="step-content">
                            <h3>Reconnect Thoughtfully</h3>
                            <p>Based on what both people prefer, you will receive helpful guidance on how to reconnect.</p>
                        </div>
                    </div>
                </div>
            </article>
        </div>
    </div>

    <script id="user-data" type="application/json"><?php echo json_encode(['user_id' => (int) $currentUser['id'], 'name' => $currentUser['name'] ?? '']); ?></script>
    <script src="/app.js"></script>
<?php
    $content = ob_get_clean();
    renderPage('Send Ping', $content, 'ping');
    exit;
}

// Dashboard page
if ($path === '/dashboard') {
    $currentUser = $userService->getCurrentUser();
    if ($currentUser === null) {
        header('Location: /login');
        exit;
    }

    $summary = getDashboardSummary($db, (int) $currentUser['id']);
    $counts = $summary['counts'];
    $pings = $summary['pings'];
    ob_start();
?>
    <div class="dashboard-page">
        <div class="page-header">
            <h1>Your Peace Ping Dashboard</h1>
            <p>Signed in as <?php echo htmlspecialchars(maskMobileNumber((string) ($currentUser['contact'] ?? ''))); ?>. Track pings, preference links, and final updates from one place.</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number" id="stat-pending"><?php echo (int) $counts['pending']; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="stat-matched"><?php echo (int) $counts['matched']; ?></div>
                <div class="stat-label">Matched</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="stat-completed"><?php echo (int) $counts['completed']; ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>

        <section class="grid dashboard-actions">
            <article class="card">
                <h2>Next Actions</h2>
                <p>Send a new ping or respond to any open preference request below.</p>
                <div class="inline-actions">
                    <a href="/ping" class="btn">Send Peace Ping</a>
                    <a href="/how-it-works" class="btn btn-secondary">How It Works</a>
                </div>
            </article>

            <article class="card">
                <h2>Privacy Reminder</h2>
                <p>Phone numbers you enter for matching are turned into secure codes and never saved. No one knows you are interested unless they also ping you back.</p>
            </article>
        </section>

        <section class="card dashboard-list">
            <h2>Submitted Pings</h2>
            <?php if (empty($pings)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">-</div>
                    <p>No pings submitted yet.</p>
                    <a href="/ping" class="btn">Send Your First Ping</a>
                </div>
            <?php else: ?>
                <?php foreach ($pings as $ping): ?>
                    <?php
                    $status = (string) ($ping['ping_status'] ?? 'pending');
                    $label = ucfirst($status);
                    $created = date('d M Y, H:i', strtotime((string) $ping['created_at']));
                    $token = (string) ($ping['token'] ?? '');
                    $recipientName = trim((string) ($ping['recipient_name'] ?? ''));
                    $targetMasked = trim((string) ($ping['target_masked'] ?? ''));
                    $displayTarget = $recipientName !== ''
                        ? $recipientName . ($targetMasked !== '' ? ' (' . $targetMasked . ')' : '')
                        : ($targetMasked !== '' ? $targetMasked : '#' . (int) $ping['id']);
                    $finalMessage = ($status === 'completed' && !empty($ping['match_id']))
                        ? $peacePingService->getFinalMessageForMatch((int) $ping['match_id'])
                        : null;
                    $daysLeft = (int) ($ping['days_until_expiry'] ?? 30);
                    $otherName = $recipientName;
                    $hasSubmittedPreference = !empty($ping['pref_id']);
                    ?>
                    <div class="ping-card dashboard-ping">
                        <div class="ping-card-main">
                            <div class="ping-card-top">
                                <span class="match-date">Submitted <?php echo htmlspecialchars($created); ?></span>
                                <span class="ping-status <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($label); ?></span>
                            </div>
                            <strong>Peace Ping to <?php echo htmlspecialchars($displayTarget); ?></strong>
                            <p>
                                <?php if ($status === 'pending'): ?>
                                    Waiting for a match. The other person has not been told you sent a Peace Ping.
                                <?php elseif ($status === 'matched' && $hasSubmittedPreference): ?>
                                    Waiting for <?php echo htmlspecialchars($otherName !== '' ? $otherName : 'the other person'); ?> to submit their preference.
                                <?php elseif ($status === 'matched'): ?>
                                    Match found. Submit your preference for next steps via the <strong>Preferences</strong> button, below.
                                <?php elseif ($finalMessage !== null): ?>
                                    <?php echo nl2br(htmlspecialchars($finalMessage)); ?>
                                <?php else: ?>
                                    Completed. Check your latest SMS or final update.
                                <?php endif; ?>
                            </p>
                            <small class="expiry-note">Expires in <?php echo $daysLeft; ?> day<?php echo $daysLeft !== 1 ? 's' : ''; ?></small>
                        </div>
                        <div class="ping-actions">
                            <?php if ($token !== ''): ?>
                                <a href="/preferences?token=<?php echo urlencode($token); ?>" class="btn btn-sm btn-secondary">Preferences</a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-danger btn-delete-ping" data-ping-id="<?php echo (int) $ping['id']; ?>" data-status="<?php echo $status; ?>"><?php echo $status === 'pending' ? 'Cancel' : 'Delete'; ?></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>

    <script>
        document.querySelectorAll('.btn-delete-ping').forEach(btn => {
            btn.addEventListener('click', async function() {
                const pingId = this.dataset.pingId;
                const status = this.dataset.status;
                const isMatched = status === 'matched' || status === 'completed';

                const confirmed = await showConfirm(
                    isMatched ? 'Delete Peace Ping?' : 'Cancel Peace Ping?',
                    isMatched
                        ? 'This will remove this Ping from your dashboard only. The other person will not be affected. This cannot be undone.'
                        : 'This will permanently remove this Peace Ping. The other person will never know you sent it. This cannot be undone.',
                    isMatched ? 'Yes, Delete Ping' : 'Yes, Cancel Ping'
                );
                if (!confirmed) return;

                this.disabled = true;
                this.textContent = 'Cancelling...';

                try {
                    const response = await fetch('/api/delete-ping', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ ping_id: parseInt(pingId) })
                    });
                    const data = await response.json();

                    if (response.ok && data.success) {
                        this.closest('.ping-card').remove();
                        if (data.counts) {
                            document.getElementById('stat-pending').textContent = data.counts.pending;
                            document.getElementById('stat-matched').textContent = data.counts.matched;
                            document.getElementById('stat-completed').textContent = data.counts.completed;
                        }
                    } else {
                        await showAlert('Could Not Delete', data.error || 'Could not delete ping.');
                        this.disabled = false;
                        this.textContent = 'Cancel';
                    }
                } catch (e) {
                    await showAlert('Error', 'Error cancelling ping.');
                    this.disabled = false;
                    this.textContent = 'Cancel';
                }
            });
        });
    </script>
<?php
    $content = ob_get_clean();
    renderPage('Dashboard', $content, 'dashboard');
    exit;
}


// FAQ page
if ($path === '/faq') {
    ob_start();
?>
    <div class="faq-page">
        <div class="page-header">
            <h1>Frequently Asked Questions</h1>
            <p>Everything you need to know about Peace Ping</p>
        </div>

        <section class="faq-list">
            <div class="faq-item">
                <h3>Is my information secure?</h3>
                <p>Yes. Your contact details are protected for safety. The numbers you enter to find someone are turned into secret codes right away, so even we never see the original numbers. Your information is only ever shared when both you and the other person both agree.</p>
            </div>
            <div class="faq-item">
                <h3>What if the other person doesn't ping me back?</h3>
                <p>Nothing happens. Your Peace Ping stays completely private, and nobody is told anything. The other person will never know you pinged them unless they also ping you.</p>
            </div>
            <div class="faq-item">
                <h3>Can I delete my account?</h3>
                <p>Yes. You can ask us to delete your account at any time through the Contact page and we will remove all your information.</p>
            </div>
            <div class="faq-item">
                <h3>How long does my Peace Ping stay active?</h3>
                <p>Peace Pings stay active for 30 days. If a match happens, the ping and its match stay for 30 days from the match date. After that, they are removed automatically. You can also delete any ping at any time from your Dashboard.</p>
            </div>
            <div class="faq-item">
                <h3>Can I cancel or delete a Peace Ping?</h3>
                <p>Yes. You can cancel a pending Peace Ping or delete one that has already matched from your Dashboard at any time. Once deleted, the other person will never know you sent it.</p>
            </div>
            <div class="faq-item">
                <h3>How does the matching process work?</h3>
                <p>When you send a Peace Ping, the phone number you enter is turned into a secret code right away. We then check if that person has also sent a Peace Ping with your number. If you have both pinged each other, a match is found and both of you are notified.</p>
            </div>
            <div class="faq-item">
                <h3>What happens after a match is found?</h3>
                <p>Both people receive private SMS links to share their reconnection preferences. Based on what each person prefers, we provide helpful guidance on how to reconnect. Your preferences stay private and are never shared with anyone.</p>
            </div>
        </section>
    </div>
<?php
    $content = ob_get_clean();
    renderPage('FAQ', $content, 'faq');
    exit;
}

// Contact page
if ($path === '/contact') {
    ob_start();
?>
    <div class="contact-page">
        <div class="page-header">
            <h1>Contact Us</h1>
            <p>Have questions? We're here to help.</p>
        </div>

        <div class="contact-info">
            <p>Peace Ping is designed to help people reconnect thoughtfully and safely. If you have any questions or need support, please reach out to us.</p>

            <div class="contact-methods">
                <div class="contact-method">
                    <h3><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> Support</h3>
                    <p>Need help with a Peace Ping?</p>
                    <p>Check our <a href="/faq">FAQ</a> or <a href="/how-it-works">How It Works</a> page first</p>
                </div>
                <div class="contact-method">
                    <h3><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><circle cx="12" cy="16" r="1.5"/><path d="M8 11V7a4 4 0 018 0v4"/></svg> Privacy</h3>
                    <p>Questions about privacy?</p>
                    <p>Your data security is our priority</p>
                </div>
                <div class="contact-method">
                    <h3><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="2 4 12 12 22 4"/></svg> Technical Support</h3>
                    <p>Having technical issues?</p>
                    <p>We're here to help resolve any problems you encounter.</p>
                </div>
            </div>
        </div>
    </div>
<?php
    $content = ob_get_clean();
    renderPage('Contact', $content, 'contact');
    exit;
}

// Preferences page
if ($path === '/preferences' || strpos($path, '/preferences/') === 0) {
    $token = $_GET['token'] ?? '';
    if ($token === '' && strpos($path, '/preferences/') === 0) {
        $token = basename($path);
    }

    $error = '';
    $success = '';
    $finalUpdate = '';

    // Handle POST first to avoid GET checks overriding on resubmit
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token !== '') {
        $preference = $_POST['preference'] ?? '';

        try {
            $result = $peacePingService->submitPreference($token, $preference);
            $success = $result['message'];
            if (!empty($result['final_message'])) {
                $finalUpdate = (string) $result['final_message'];
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    // GET checks only if POST didn't already set a message
    if ($success === '' && $error === '') {
        $context = $token !== '' ? $peacePingService->getPreferenceContext($token) : null;

        if ($token === '') {
            $error = 'Missing preference token.';
        } elseif ($context === null) {
            $error = 'Invalid or expired link.';
        } elseif ($context['is_completed'] && !empty($context['final_message'])) {
            $finalUpdate = (string) $context['final_message'];
        } elseif ($context['is_used'] || $context['has_preference']) {
            $success = 'Your preference has already been recorded. The final update will appear here and on your dashboard once both people have responded.';
        } elseif ($context['is_expired']) {
            $error = 'This preference link has expired.';
        }
    }

    // Ensure context is available for other_name fallback
    if (!isset($context)) {
        $context = $token !== '' ? $peacePingService->getPreferenceContext($token) : null;
    }
    $matchedName = $context['other_name'] ?? 'the other person';

    ob_start();
?>
    <div class="preferences-page">
        <div class="page-header">
            <h1>Private Coordination Preferences</h1>
            <p>You and <?php echo htmlspecialchars($matchedName); ?> have both indicated openness to reconnecting.</p>
            <p>How would you like this to proceed?</p>
        </div>

        <?php if ($success): ?>
            <div class="success-message card">
                <h3>Preference Recorded</h3>
                <p><?php echo htmlspecialchars($success); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($finalUpdate): ?>
            <div class="success-message card">
                <h3>Final Update</h3>
                <p><?php echo nl2br(htmlspecialchars($finalUpdate)); ?></p>
                <p><a href="/dashboard" class="btn btn-secondary">Open Dashboard</a></p>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message card">
                <h3>Preference Unavailable</h3>
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!$success && !$error && !$finalUpdate): ?>
            <div class="form-container">
                <form method="POST" id="preference-form">
                    <div class="preference-card" data-preference="comfortable">
                        <span class="preference-icon">A</span>
                        <div class="preference-title">I'm comfortable reaching out</div>
                        <div class="preference-description">
                            Your choice is private. It is only used to pick the right wording for your reconnection message.
                        </div>
                    </div>

                    <div class="preference-card" data-preference="prefer_other">
                        <span class="preference-icon">B</span>
                        <div class="preference-title">I prefer the other person to reach out first</div>
                        <div class="preference-description">
                            Your choice is private. It is never shown to the other person.
                        </div>
                    </div>

                    <div class="preference-card" data-preference="either">
                        <span class="preference-icon">C</span>
                        <div class="preference-title">Either is fine</div>
                        <div class="preference-description">
                            Your choice is private. It is only used to pick neutral wording for the final message.
                        </div>
                    </div>

                    <button type="submit" class="btn submit-btn" id="submit-btn" disabled>
                        Please select one option above
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-top: var(--space-2xl);">
            <h3><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><circle cx="12" cy="16" r="1.5"/><path d="M8 11V7a4 4 0 018 0v4"/></svg> Your Privacy</h3>
            <p>Your choices are private and never shared with anyone. They are only used to guide how Peace Ping describes your reconnection.</p>
        </div>
    </div>

    <script>
        const preferenceCards = document.querySelectorAll('.preference-card');
        const submitBtn = document.getElementById('submit-btn');
        const form = document.getElementById('preference-form');
        let selectedPreference = null;

        if (form && submitBtn) {
            preferenceCards.forEach((card) => {
                card.addEventListener('click', function() {
                    preferenceCards.forEach((item) => item.classList.remove('selected'));
                    this.classList.add('selected');
                    selectedPreference = this.dataset.preference;
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit my private preference';

                    let hiddenInput = form.querySelector('input[name="preference"]');
                    if (!hiddenInput) {
                        hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'preference';
                        form.appendChild(hiddenInput);
                    }
                    hiddenInput.value = selectedPreference;
                });
            });

            form.addEventListener('submit', function(event) {
                if (!selectedPreference) {
                    event.preventDefault();
                    alert('Please select a preference before submitting.');
                    return;
                }

                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting...';
            });
        }
    </script>
<?php
    $content = ob_get_clean();
    renderPage('Preferences', $content, 'preferences');
    exit;
}
// 404 page
Response::json(['error' => 'Page not found.'], 404);
?>