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
    $activeNav = [
        'home' => 'Home',
        'how-it-works' => 'How It Works',
        'register' => 'Register & Verify',
        'ping' => 'Send Ping',
        'contact' => 'Contact'
    ];

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
        <link rel="stylesheet" href="/styles.css">
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
    </body>

    </html>
<?php
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
            $rateLimiter->enforcePingLimit($_SERVER['REMOTE_ADDR']);
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
                    <p>Your contact information is encrypted and only shared when there's mutual interest.</p>
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
                <li><strong>Encrypted Storage:</strong> Your contact information is encrypted and stored securely</li>
                <li><strong>No Unwanted Contact:</strong> No information is shared unless there's mutual interest</li>
                <li><strong>You're in Control:</strong> You decide when and how to reconnect</li>
                <li><strong>Anonymous Until Match:</strong> Your identity remains private until mutual connection</li>
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
                        <input type="tel" id="register-phone" name="phone" placeholder="+1234567890 or 1234567890" required>
                        <small>International format: +1234567890 or local format: 1234567890. Your number is encrypted and never shared.</small>
                    </div>
                    <button type="submit" class="btn">Send Verification Code</button>
                </form>
                <p id="register-result" class="result" aria-live="polite"></p>

                <!-- Verification form (hidden initially) -->
                <form id="verify-form" style="display: none;">
                    <div class="form-group">
                        <label for="verify-code">Verification Code</label>
                        <input id="verify-code" name="code" type="text" required placeholder="123456" maxlength="6">
                    </div>
                    <button type="submit" class="btn">Verify & Create Account</button>
                </form>
                <p id="verify-result" class="result" aria-live="polite"></p>
            </article>

            <article class="card">
                <h2>🔐 Already Registered?</h2>
                <p>If you already have an account, you can log in by requesting a new verification code.</p>
                <div style="margin-top: var(--space-lg);">
                    <a href="/ping" class="btn btn-secondary">Go to Send Ping</a>
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

        registerForm.addEventListener("submit", async (event) => {
            event.preventDefault();

            const phone = document.getElementById("register-phone").value.trim();

            if (phone === '') {
                showResult(registerResult, "Please enter your mobile number.", "warn");
                return;
            }

            showResult(registerResult, "Sending verification code...", null);

            const response = await postJson("api/register", {
                action: 'register',
                phone: phone
            });

            if (!response.ok) {
                const err = response.body.error || "Registration failed.";
                showResult(registerResult, err, "warn");
                return;
            }

            showResult(registerResult, response.body.message, "ok");

            // Show verification form
            registerForm.style.display = "none";
            verifyForm.style.display = "block";
        });

        // Verification form handler
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

            showResult(verifyResult, "Account created successfully! Redirecting...", "ok");

            // Redirect to ping page after 2 seconds
            setTimeout(() => {
                window.location.href = '/ping';
            }, 2000);
        });
    </script>
<?php
    $content = ob_get_clean();
    renderPage('Register & Verify', $content, 'register');
    exit;
}

// Ping page
if ($path === '/ping') {
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
                        <label for="ping-target">Mobile Number to Ping</label>
                        <input type="tel" id="ping-target" name="target" placeholder="+1234567890 or 1234567890" required>
                        <small>International format: +1234567890 or local format: 1234567890</small>
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
    $context = $token !== '' ? $peacePingService->getPreferenceContext($token) : null;

    if ($token === '') {
        $error = 'Missing preference token.';
    } elseif ($context === null) {
        $error = 'Invalid or expired link.';
    } elseif ($context['is_used']) {
        $error = 'This preference link has already been used.';
    } elseif ($context['is_expired']) {
        $error = 'This preference link has expired.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
        $preference = $_POST['preference'] ?? '';

        try {
            $result = $peacePingService->submitPreference($token, $preference);
            $success = $result['message'];
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

        <?php if ($error): ?>
            <div class="error-message card">
                <h3>Preference Unavailable</h3>
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!$success && !$error): ?>
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
