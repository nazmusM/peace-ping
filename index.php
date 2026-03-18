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
    $config['security']['pepper']
);
$rateLimiter = new RateLimiter(
    $db,
    $config['security']['pepper'],
    (int) $config['rate_limit']['max_pings_per_hour']
);

// Enable error reporting for production debugging
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

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

// Page content functions
function renderPage(string $title, string $content, string $page = 'home'): void
{
    $activeNav = [
        'home' => 'Home',
        'how-it-works' => 'How It Works',
        'register' => 'Register & Verify',
        'ping' => 'Send Ping',
        'matches' => 'My Matches',
        'contact' => 'Contact'
    ];

?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                            <a href="/<?php echo $route; ?>" <?php echo $route === $page ? 'class="active"' : ''; ?>>
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
                <p>&copy; 2024 Peace Ping. Reconnecting people thoughtfully.</p>
            </div>
        </footer>
    </body>

    </html>
<?php
}

// API Routes
if (strpos($_SERVER['REQUEST_URI'], '/api/') === 0) {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

    if ($method !== 'GET' && $method !== 'POST') {
        Response::json(['error' => 'Not found.'], 404);
        exit;
    }

    // API Routes
    if ($path === '/api/register') {
        $userController = new UserController($userService, $notificationService);
        $userController->handle();
        exit;
    }

    if ($path === '/api/ping') {
        $pingController = new PingController($pingService, $rateLimiter);
        $pingController->handle($_SERVER['REMOTE_ADDR']);
        exit;
    }

    // 404 for API
    Response::json(['error' => 'API endpoint not found.'], 404);
}

// Page Routes
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

if ($method !== 'GET') {
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
                        <label for="register-name">Your Name</label>
                        <input id="register-name" name="name" type="text" required placeholder="John Smith">
                    </div>
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
                    <button type="submit">Verify & Create Account</button>
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

            const name = document.getElementById("register-name").value.trim();
            const phone = document.getElementById("register-phone").value.trim();

            if (name === '' || phone === '') {
                showResult(registerResult, "Please fill in all fields.", "warn");
                return;
            }

            showResult(registerResult, "Sending verification code...", null);

            const response = await postJson("api/register", {
                action: 'register',
                name: name,
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

// Matches page
if ($path === '/matches') {
    // Check if user is logged in
    session_start();
    if (!isset($_SESSION['user_id'])) {
        header('Location: /register');
        exit;
    }

    $userId = $_SESSION['user_id'];
    $user = $userService->getUserById($userId);

    // Get user's matches
    $matches = [];
    $matchQuery = $db->prepare("
        SELECT m.*, 
               u1.name as user_a_name,
               u2.name as user_b_name,
               m.created_at as match_date,
               CASE 
                   WHEN m.user_a_id = ? THEN 'You initiated'
                   WHEN m.user_b_id = ? THEN 'They initiated'
                   ELSE 'Unknown'
               END as initiator
        FROM matches m
        LEFT JOIN users u1 ON m.user_a_id = u1.id
        LEFT JOIN users u2 ON m.user_b_id = u2.id
        WHERE m.user_a_id = ? OR m.user_b_id = ?
        ORDER BY m.created_at DESC
    ");
    $matchQuery->bind_param('iiii', $userId, $userId, $userId, $userId);
    $matchQuery->execute();
    $result = $matchQuery->get_result();

    while ($row = $result->fetch_assoc()) {
        $matches[] = $row;
    }
    $matchQuery->close();

    // Get user's recent pings
    $recentPings = [];
    $pingQuery = $db->prepare("
        SELECT p.*, 
               CASE 
                   WHEN EXISTS (
                       SELECT 1 FROM matches m 
                       WHERE (m.user_a_id = p.user_id OR m.user_b_id = p.user_id)
                       AND m.fingerprint_a = p.fingerprint_target
                   ) THEN 1
                   ELSE 0
               END as has_match
        FROM pings p
        WHERE p.user_id = ?
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $pingQuery->bind_param('i', $userId);
    $pingQuery->execute();
    $pingResult = $pingQuery->get_result();

    while ($row = $pingResult->fetch_assoc()) {
        $recentPings[] = $row;
    }
    $pingQuery->close();

    ob_start();
?>
    <div class="matches-page">
        <div class="page-header">
            <h1>My Matches</h1>
            <p>See who you've connected with through Peace Ping</p>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($matches); ?></div>
                <div class="stat-label">Total Matches</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_filter($recentPings, fn($p) => $p['has_match'])); ?></div>
                <div class="stat-label">Matched Pings</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_filter($recentPings, fn($p) => !$p['has_match'])); ?></div>
                <div class="stat-label">Pending Pings</div>
            </div>
        </div>

        <!-- Matches Section -->
        <section>
            <h2>🎉 Your Matches</h2>
            <?php if (empty($matches)): ?>
                <div class="empty-state card">
                    <div class="empty-state-icon">💭</div>
                    <h3>No matches yet</h3>
                    <p>Keep sending Peace Pings! When someone you ping also pings you, you'll see your matches here.</p>
                    <a href="/ping" class="btn">Send a Peace Ping</a>
                </div>
            <?php else: ?>
                <?php foreach ($matches as $match): ?>
                    <div class="match-card">
                        <div class="match-status">MATCH FOUND</div>
                        <div class="match-date">Matched on <?php echo date('F j, Y \a\t g:i A', strtotime($match['match_date'])); ?></div>
                        <h3>🎊 You have a mutual connection!</h3>
                        <p><strong>Status:</strong> <?php echo htmlspecialchars($match['initiator']); ?></p>
                        <p><strong>Next Steps:</strong> Both you and your match have received SMS messages with questions to help you reconnect comfortably.</p>
                        <div style="margin-top: var(--space-md);">
                            <small style="color: var(--muted);">
                                💡 Check your mobile phone for SMS questions. This is your opportunity to reconnect thoughtfully.
                            </small>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <!-- Recent Pings Section -->
        <section style="margin-top: var(--space-2xl);">
            <h2>📤 Recent Peace Pings</h2>
            <?php if (empty($recentPings)): ?>
                <div class="empty-state card">
                    <div class="empty-state-icon">📭</div>
                    <h3>No pings sent yet</h3>
                    <p>Start by sending your first Peace Ping to reconnect with someone.</p>
                    <a href="/ping" class="btn">Send a Peace Ping</a>
                </div>
            <?php else: ?>
                <?php foreach ($recentPings as $ping): ?>
                    <div class="ping-card">
                        <div>
                            <strong>Ping sent to:</strong>
                            <span style="color: var(--muted);">
                                <?php
                                $target = substr($ping['fingerprint_target'], 0, 8) . '...';
                                echo htmlspecialchars($target);
                                ?>
                            </span>
                            <br>
                            <small style="color: var(--muted);">
                                <?php echo date('M j, Y \a\t g:i A', strtotime($ping['created_at'])); ?>
                            </small>
                        </div>
                        <div>
                            <span class="ping-status <?php echo $ping['has_match'] ? 'matched' : 'pending'; ?>">
                                <?php echo $ping['has_match'] ? '✅ Matched' : '⏳ Pending'; ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
<?php
    $content = ob_get_clean();
    renderPage('My Matches', $content, 'matches');
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
if (strpos($path, '/preferences/') === 0) {
    // Get token from URL
    $token = $_GET['token'] ?? '';
    $error = '';
    $success = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $preference = $_POST['preference'] ?? '';

        try {
            $result = $peacePingService->submitPreference($token, $preference);
            $success = $result['message'];
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    ob_start();
?>
    <div class="preferences-page">
        <div class="page-header">
            <h1>🕊️ Peace Ping Match!</h1>
            <p>Someone you're thinking about is also thinking about you. Please share your preferences for reconnecting.</p>
        </div>

        <?php if ($success): ?>
            <div class="success-message card">
                <h3>✅ Thank You!</h3>
                <p><?php echo htmlspecialchars($success); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message card">
                <h3>❌ Error</h3>
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!$success): ?>
            <div class="form-container">
                <form method="POST" id="preference-form">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                    <div class="preference-card" data-preference="comfortable">
                        <span class="preference-icon">🤝</span>
                        <div class="preference-title">I'm comfortable reaching out</div>
                        <div class="preference-description">
                            I'm happy to make the first move and reconnect directly. I feel confident about reaching out to reestablish our connection.
                        </div>
                    </div>

                    <div class="preference-card" data-preference="prefer_other">
                        <span class="preference-icon">🙏</span>
                        <div class="preference-title">I prefer the other person to reach out</div>
                        <div class="preference-description">
                            I'd be more comfortable if the other person initiates the reconnection. I'm open to reconnecting but prefer they take the lead.
                        </div>
                    </div>

                    <div class="preference-card" data-preference="either">
                        <span class="preference-icon">🌟</span>
                        <div class="preference-title">Either way is fine with me</div>
                        <div class="preference-description">
                            I'm comfortable either way - whether I reach out or they do. The important thing is that we reconnect, regardless of who initiates.
                        </div>
                    </div>

                    <button type="submit" class="btn submit-btn" id="submit-btn" disabled>
                        Please select your preference above
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-top: var(--space-2xl);">
            <h3>🔒 Your Privacy Matters</h3>
            <p>Your preference is completely confidential and will only be used to determine the most appropriate way to facilitate your reconnection. The other person will not see your specific choice - they will only receive a neutral message based on both of your preferences.</p>

            <div style="margin-top: var(--space-lg);">
                <h4>What happens next?</h4>
                <ul style="color: var(--muted); line-height: 1.8;">
                    <li>Both people share their preferences privately</li>
                    <li>Our system determines the best approach based on both preferences</li>
                    <li>You'll both receive a final message with guidance for reconnection</li>
                    <li>The final message respects both people's comfort levels</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        // Preference selection handling
        const preferenceCards = document.querySelectorAll('.preference-card');
        const submitBtn = document.getElementById('submit-btn');
        const form = document.getElementById('preference-form');
        let selectedPreference = null;

        preferenceCards.forEach(card => {
            card.addEventListener('click', function() {
                // Remove selected class from all cards
                preferenceCards.forEach(c => c.classList.remove('selected'));

                // Add selected class to clicked card
                this.classList.add('selected');

                // Store the preference
                selectedPreference = this.dataset.preference;

                // Update submit button
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit My Preference';

                // Add hidden input for form submission
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

        // Form submission
        form.addEventListener('submit', function(e) {
            if (!selectedPreference) {
                e.preventDefault();
                alert('Please select your preference before submitting.');
                return;
            }

            // Disable submit button to prevent double submission
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
        });
    </script>
<?php
    $content = ob_get_clean();
    renderPage('Preferences', $content, 'preferences');
    exit;
}

// 404 page
Response::json(['error' => 'Page not found.'], 404);
?>