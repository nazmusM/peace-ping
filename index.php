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
ini_set('log_errors', 1);      // Log errors to file
error_reporting(E_ALL);        // Report all errors

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

// Session security configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 0);  // Set to 1 if using HTTPS
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
}

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
            <div class="container">
                <p>&copy; <?php echo date('Y') ?> Peace Ping. Reconnecting people thoughtfully.</p>
            </div>
        </footer>
        <?php if ($isLoggedIn): ?>
            <script>
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
                    window.location.href = '/';
                });
            </script>
        <?php endif; ?>
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
                mt.token, mt.is_used, mt.expires_at,
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
         WHERE p.user_id = ?
         ORDER BY p.created_at DESC"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
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
                <h1>Mutual Reconnection, Without Pressure</h1>
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
                    <h3>🕊️ Anonymous Interest</h3>
                    <p>Send a Peace Ping to someone you're thinking about. They won't know unless they also ping you back.</p>
                </div>
                <div class="feature">
                    <h3>🤝 Mutual Connection</h3>
                    <p>Only when both people ping each other does a connection happen. No awkward conversations!</p>
                </div>
                <div class="feature">
                    <h3>🔒 Privacy First</h3>
                    <p>Numbers used for matching are fingerprinted with a private server secret. Raw submitted target numbers are not stored.</p>
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
            <p>Simple, thoughtful reconnection without the pressure of direct outreach.</p>
        </div>

        <div class="steps">
            <div class="step">
                <div class="step-number">1</div>
                <div class="step-content">
                    <h3>Send Your Peace Ping</h3>
                    <p>Think of someone you'd like to reconnect with and send them a Peace Ping using their contact information.</p>
                </div>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <div class="step-content">
                    <h3>Wait for Mutual Interest</h3>
                    <p>If they also send you a Peace Ping, our system detects a mutual connection and notifies both of you.</p>
                </div>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <div class="step-content">
                    <h3>Share Your Preferences</h3>
                    <p>Both people receive private links to share their comfort level with reconnecting.</p>
                </div>
            </div>
            <div class="step">
                <div class="step-number">4</div>
                <div class="step-content">
                    <h3>Reconnect Thoughtfully</h3>
                    <p>Based on both preferences, you'll receive guidance on the best way to reconnect.</p>
                </div>
            </div>
        </div>

        <div class="privacy card">
            <h2>🔒 Your Privacy Matters</h2>
            <p>Peace Ping is designed with privacy at its core:</p>
            <ul>
                <li><strong>Fingerprint Matching:</strong> Submitted numbers are normalized, fingerprinted, and compared as hashes</li>
                <li><strong>No Raw Target Storage:</strong> Raw numbers entered for matching are not stored after submission</li>
                <li><strong>Limited Visibility:</strong> The platform cannot read raw submitted target numbers or a raw-number "who wants whom" list</li>
                <li><strong>Encrypted Account Contact:</strong> Your own verified mobile is encrypted for SMS delivery</li>
                <li><strong>No Unwanted Contact:</strong> No information is shared unless there's mutual interest</li>
                <li><strong>You're in Control:</strong> You decide when and how to reconnect</li>
            </ul>
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
                <h2>📱 Register Your Account</h2>
                <form id="register-form">
                    <div class="form-group">
                        <label for="register-phone">Mobile Number</label>
                        <input type="tel" id="register-phone" name="phone" placeholder="07xxx xxxxxx or +447xxx xxxxxx" autocomplete="tel" inputmode="tel" required>
                        <small>Use a UK mobile such as 07xxx xxxxxx or +447xxx xxxxxx. International +[country code][number] also works. Spaces are fine.</small>
                    </div>
                    <div class="form-group">
                        <label for="register-phone-confirm">Confirm Mobile Number</label>
                        <input type="tel" id="register-phone-confirm" name="confirm_phone" placeholder="Re-enter your mobile number" autocomplete="tel" inputmode="tel" required>
                        <p class="field-error" id="register-phone-confirm-error" aria-live="polite"></p>
                    </div>
                    <div class="form-group">
                        <label for="register-password">Password</label>
                        <input type="password" id="register-password" name="password" placeholder="Create a password" autocomplete="new-password" required minlength="8">
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
                        <input type="password" id="register-password-confirm" name="confirm_password" placeholder="Re-enter your password" autocomplete="new-password" required minlength="8">
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
                <h2>🔐 Already Registered?</h2>
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

            // Scroll to result for better UX on mobile
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
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
            if (message) {
                showResult(registerResult, message, 'warn');
                submitButton.disabled = true;
            } else if (phoneError || passwordError) {
                showResult(registerResult, '', null);
                submitButton.disabled = true;
            } else {
                showResult(registerResult, '', null);
                submitButton.disabled = false;
            }
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
                <form id="login-form">
                    <div class="form-group">
                        <label for="login-phone">Mobile Number</label>
                        <input type="tel" id="login-phone" name="phone" placeholder="07xxx xxxxxx or +447xxx xxxxxx" autocomplete="tel" inputmode="tel" required>
                        <small>Enter the mobile number you used when registering.</small>
                    </div>
                    <div class="form-group">
                        <label for="login-password">Password</label>
                        <input type="password" id="login-password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                    </div>
                    <button type="submit" class="btn">Log In</button>
                </form>
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

        const loginForm = document.getElementById('login-form');
        const loginResult = document.getElementById('login-result');

        loginForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const phone = document.getElementById('login-phone').value.trim();
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
                phone,
                password
            });

            if (!response.ok) {
                showResult(loginResult, response.body.error || 'Login failed.', 'warn');
                return;
            }

            showResult(loginResult, response.body.message, 'ok');
            window.location.href = '/dashboard';
        });
    </script>
<?php
    $content = ob_get_clean();
    renderPage('Login', $content, 'login');
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

        <section class="grid">
            <article class="card">
                <h2>📤 Send Your Peace Ping</h2>
                <form id="ping-form">
                    <div class="form-group">
                        <label for="recipient-name">Recipient Name (Optional)</label>
                        <input type="text" id="recipient-name" name="recipient_name" placeholder="Jane Doe" maxlength="120" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="ping-target">Mobile Number to Ping</label>
                        <input type="tel" id="ping-target" name="target" placeholder="07xxx xxxxxx or +447xxx xxxxxx" autocomplete="tel" inputmode="tel" required>
                        <small>Use 07xxx xxxxxx, +447xxx xxxxxx, or international +[country code][number]. We auto-normalise spacing and common UK formats.</small>
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
                    <h3>🎊 Match Found!</h3>
                    <p id="match-message"></p>
                    <div id="next-steps">
                        <h4>What happens next?</h4>
                        <p>Both you and the other person will receive SMS messages with questions to help you reconnect comfortably.</p>
                    </div>
                </div>
            </article>

            <article class="card">
                <h2>🔒 What We Store</h2>
                <p>For matching, the number you enter is immediately normalized and fingerprinted with a private server secret.</p>
                <ul class="trust-list">
                    <li>Raw submitted target numbers are not stored.</li>
                    <li>Matching compares fingerprints, not readable phone numbers.</li>
                    <li>No one is notified unless both people independently ping each other.</li>
                </ul>
            </article>

            <article class="card">
                <h2>🤔 How It Works</h2>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h3>You Send a Ping</h3>
                            <p>Submit someone's contact information. They won't know unless they also ping you.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h3>They Send a Ping Too</h3>
                            <p>If they also ping you, our system creates a match and notifies both of you.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h3>Private Links Sent</h3>
                            <p>Both receive secure SMS links to share reconnection preferences.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">4</div>
                        <div class="step-content">
                            <h3>Final Messages</h3>
                            <p>Based on preferences, you receive guidance for reconnection.</p>
                        </div>
                    </div>
                </div>
            </article>
        </section>
    </div>

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
                <div class="stat-number"><?php echo (int) $counts['pending']; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo (int) $counts['matched']; ?></div>
                <div class="stat-label">Matched</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo (int) $counts['completed']; ?></div>
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
                <p>Target numbers are fingerprinted for matching and raw submitted numbers are not stored. The platform does not reveal interest unless both sides independently match.</p>
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
                    ?>
                    <div class="ping-card dashboard-ping">
                        <div>
                            <div class="match-date">Submitted <?php echo htmlspecialchars($created); ?></div>
                            <strong>Peace Ping to <?php echo htmlspecialchars($displayTarget); ?></strong>
                            <p>
                                <?php if ($status === 'pending'): ?>
                                    Waiting for mutual interest. No notification has been sent to the other person.
                                <?php elseif ($status === 'matched' && $token !== ''): ?>
                                    Matched. Your private preference selection is ready.
                                <?php elseif ($status === 'matched'): ?>
                                    Matched. Waiting for preference updates or final message.
                                <?php elseif ($finalMessage !== null): ?>
                                    <?php echo nl2br(htmlspecialchars($finalMessage)); ?>
                                <?php else: ?>
                                    Completed. Check your latest SMS or final update.
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="ping-actions">
                            <span class="ping-status <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($label); ?></span>
                            <?php if ($token !== ''): ?>
                                <a href="/preferences?token=<?php echo urlencode($token); ?>" class="btn btn-secondary">Preferences</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
<?php
    $content = ob_get_clean();
    renderPage('Dashboard', $content, 'dashboard');
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
                    <h3>💬 Support</h3>
                    <p>Need help with a Peace Ping?</p>
                    <p>Check our <a href="/how-it-works">How It Works</a> page first</p>
                </div>
                <div class="contact-method">
                    <h3>🔒 Privacy</h3>
                    <p>Questions about privacy?</p>
                    <p>Your data security is our priority</p>
                </div>
                <div class="contact-method">
                    <h3>📧 Technical Support</h3>
                    <p>Having technical issues?</p>
                    <p>We're here to help resolve any problems you encounter.</p>
                </div>
            </div>
        </div>

        <section class="faq">
            <h2>Frequently Asked Questions</h2>
            <div class="faq-item">
                <h3>Is my information secure?</h3>
                <p>Yes. All contact information is encrypted and only shared when there's mutual consent.</p>
            </div>
            <div class="faq-item">
                <h3>What if the other person doesn't ping me back?</h3>
                <p>Nothing happens. Your Peace Ping remains private, and no information is shared.</p>
            </div>
            <div class="faq-item">
                <h3>Can I delete my account?</h3>
                <p>Yes. You can request deletion at any time and we'll remove all your information.</p>
            </div>
            <div class="faq-item">
                <h3>How long does my Peace Ping stay active?</h3>
                <p>Peace Pings remain active for 30 days. After that, they expire and you can send new ones.</p>
            </div>
        </section>
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
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
                            Your selection stays private. It is used only to choose the system wording sent to both people.
                        </div>
                    </div>

                    <div class="preference-card" data-preference="prefer_other">
                        <span class="preference-icon">B</span>
                        <div class="preference-title">I prefer the other person to reach out first</div>
                        <div class="preference-description">
                            Your selection stays private. It is never shown to the other person and never used to assign responsibility.
                        </div>
                    </div>

                    <div class="preference-card" data-preference="either">
                        <span class="preference-icon">C</span>
                        <div class="preference-title">Either is fine</div>
                        <div class="preference-description">
                            Your selection stays private. The system uses it only to choose neutral wording for the final permission message.
                        </div>
                    </div>

                    <button type="submit" class="btn submit-btn" id="submit-btn" disabled>
                        Please select one option above
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-top: var(--space-2xl);">
            <h3>Your Privacy</h3>
            <p>These selections are never shown to the other person, never used to assign responsibility, and used only to choose the system wording.</p>
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