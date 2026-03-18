<?php

use App\Controllers\PingController;
use App\Controllers\UserController;
use App\Database\Database;
use App\Fingerprint;
use App\Services\MatchService;
use App\Services\NotificationService;
use App\Services\PingService;
use App\Services\SmsService;
use App\Services\UserService;
use App\Utils\Encryption;
use App\Utils\RateLimiter;
use App\Utils\Response;

require_once __DIR__ . '/src/bootstrap.php';

$db = Database::getConnection($config['db']);
$fingerprint = new Fingerprint();
$encryption = new Encryption($config['security']['encryption_key'] ?? '');
$userService = new UserService($db, $fingerprint, $encryption, $config['security']['pepper']);
$smsService = new SmsService($config);
$matchService = new MatchService($db);
$notificationService = new NotificationService($smsService, $encryption);
$pingService = new PingService(
    $db,
    $fingerprint,
    $userService,
    $matchService,
    $notificationService,
    $config['security']['pepper']
);
$rateLimiter = new RateLimiter(
    $db,
    $config['security']['pepper'],
    (int) $config['rate_limit']['max_pings_per_hour']
);

$pingController = new PingController($pingService, $rateLimiter);
$userController = new UserController($userService);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// API Routes
if ($method === 'POST' && preg_match('#/api/register$#', $path) === 1) {
    $userController->handle();
    exit;
}

if ($method === 'POST' && preg_match('#/api/ping$#', $path) === 1) {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $pingController->handle($clientIp);
    exit;
}

if ($method !== 'GET') {
    Response::json(['error' => 'Not found.'], 404);
    exit;
}

// Page Routes
if ($path === '/' || $path === '/home') {
    // Homepage
?>
    <!doctype html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Peace Ping - Reconnect with Peace</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="styles.css?v=<?= time() ?>">
    </head>

    <body>
        <header class="header">
            <nav class="nav">
                <div class="nav-brand">
                    <h1>Peace Ping</h1>
                </div>
                <ul class="nav-links">
                    <li><a href="/" class="active">Home</a></li>
                    <li><a href="/how-it-works">How It Works</a></li>
                    <li><a href="/register">Register</a></li>
                    <li><a href="/ping">Send Ping</a></li>
                    <li><a href="/contact">Contact</a></li>
                </ul>
            </nav>
        </header>

        <main class="homepage">
            <section class="hero">
                <div class="hero-content">
                    <p class="kicker">Peace Ping</p>
                    <h1>Mutual Reconnection, Without Pressure</h1>
                    <p>
                        Sometimes you want to reconnect, but you're not sure if the other person feels the same.
                        Peace Ping ensures both sides independently indicate openness before any connection happens.
                    </p>
                    <div class="hero-actions">
                        <a href="/ping" class="btn btn-primary">Send a Peace Ping</a>
                        <a href="/how-it-works" class="btn btn-secondary">Learn More</a>
                    </div>
                </div>
            </section>

            <section class="features">
                <div class="container">
                    <h2>Why Peace Ping?</h2>
                    <div class="feature-grid">
                        <div class="feature">
                            <h3>🔒 Privacy First</h3>
                            <p>Your information is encrypted and only shared when there's mutual openness.</p>
                        </div>
                        <div class="feature">
                            <h3>🤝 Mutual Consent</h3>
                            <p>No awkwardness. Connections only happen when both people independently agree.</p>
                        </div>
                        <div class="feature">
                            <h3>📱 Simple Process</h3>
                            <p>Just enter their contact. If they ping you too, we'll help you reconnect.</p>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer class="footer">
            <div class="container">
                <p>&copy; 2024 Peace Ping. Reconnecting people with peace of mind.</p>
            </div>
        </footer>
    </body>

    </html>
<?php
    exit;
}

if ($path === '/how-it-works') {
    // How It Works page
?>
    <!doctype html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>How It Works - Peace Ping</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="styles.css?v=<?= time() ?>">
    </head>

    <body>
        <header class="header">
            <nav class="nav">
                <div class="nav-brand">
                    <h1>Peace Ping</h1>
                </div>
                <ul class="nav-links">
                    <li><a href="/">Home</a></li>
                    <li><a href="/how-it-works" class="active">How It Works</a></li>
                    <li><a href="/register">Register</a></li>
                    <li><a href="/ping">Send Ping</a></li>
                    <li><a href="/contact">Contact</a></li>
                </ul>
            </nav>
        </header>

        <main class="how-it-works">
            <div class="container">
                <section class="page-header">
                    <h1>How Peace Ping Works</h1>
                    <p>Simple, private, and respectful reconnection</p>
                </section>

                <section class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h3>Send a Peace Ping</h3>
                            <p>Enter your contact information and the person you'd like to reconnect with.</p>
                        </div>
                    </div>

                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h3>Wait for Mutual Interest</h3>
                            <p>If that person also sends you a Peace Ping, we detect the mutual interest.</p>
                        </div>
                    </div>

                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h3>Get Connected</h3>
                            <p>Both people receive SMS notifications with questions to help reconnection happen comfortably.</p>
                        </div>
                    </div>
                </section>

                <section class="privacy">
                    <h2>Privacy & Security</h2>
                    <ul>
                        <li>✅ Your contact information is encrypted and stored securely</li>
                        <li>✅ No information is shared without mutual consent</li>
                        <li>✅ We never store third-party contact information</li>
                        <li>✅ You can opt out at any time</li>
                    </ul>
                </section>

                <section class="cta">
                    <h2>Ready to Reconnect?</h2>
                    <p>Take the first step towards peaceful reconnection.</p>
                    <a href="/ping" class="btn btn-primary">Send Your First Peace Ping</a>
                </section>
            </div>
        </main>

        <footer class="footer">
            <div class="container">
                <p>&copy; 2024 Peace Ping. Reconnecting people with peace of mind.</p>
            </div>
        </footer>
    </body>

    </html>
<?php
    exit;
}

if ($path === '/register') {
    // Register page
?>
    <!doctype html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Register - Peace Ping</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="styles.css?v=<?= time() ?>">
    </head>

    <body>
        <header class="header">
            <nav class="nav">
                <div class="nav-brand">
                    <h1>Peace Ping</h1>
                </div>
                <ul class="nav-links">
                    <li><a href="/">Home</a></li>
                    <li><a href="/how-it-works">How It Works</a></li>
                    <li><a href="/register" class="active">Register</a></li>
                    <li><a href="/ping">Send Ping</a></li>
                    <li><a href="/contact">Contact</a></li>
                </ul>
            </nav>
        </header>

        <main class="register-page">
            <div class="container">
                <section class="page-header">
                    <h1>Create Your Peace Ping Account</h1>
                    <p>Register with your UK mobile number to start reconnecting</p>
                </section>

                <section class="grid">
                    <article class="card">
                        <h2>📱 Register Your Account</h2>
                        <form id="register-form">
                            <div class="form-group">
                                <label for="register-name">Your Name</label>
                                <input id="register-name" name="name" type="text" required placeholder="John Smith">
                            </div>
                            <div class="form-group">
                                <label for="register-phone">Your Mobile Number</label>
                                <input id="register-phone" name="phone" type="tel" required placeholder="07xxx xxxxxx">
                                <small>Format: 07xxx xxxxxx or +447xx xxxxxx</small>
                            </div>
                            <button type="submit">Send Verification Code</button>
                        </form>
                        <p id="register-result" class="result" aria-live="polite"></p>

                        <!-- Verification form (hidden initially) -->
                        <form id="verify-form" style="display: none;">
                            <div class="form-group">
                                <label for="verify-code">Verification Code</label>
                                <input id="verify-code" name="code" type="text" required placeholder="123456" maxlength="6">
                                <small>Enter the 6-digit code sent to your phone</small>
                            </div>
                            <button type="submit">Verify & Create Account</button>
                        </form>
                        <p id="verify-result" class="result" aria-live="polite"></p>
                    </article>

                    <article class="card">
                        <h2>📋 Why Register?</h2>
                        <ul>
                            <li>✅ Securely store your number for Peace Pings</li>
                            <li>✅ Receive SMS notifications when matched</li>
                            <li>✅ Privacy-first - only shared with mutual consent</li>
                            <li>✅ Mobile number verification for security</li>
                        </ul>
                        <div style="margin-top: 1.5rem;">
                            <p><strong>Already registered?</strong></p>
                            <a href="/ping" class="btn btn-secondary">Go to Send Ping</a>
                        </div>
                    </article>
                </section>
            </div>
        </main>

        <footer class="footer">
            <div class="container">
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
                        target.textContent = text;
                        target.classList.remove("ok", "warn");
                        if (tone) {
                            target.classList.add(tone);
                        }
                    }

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

                        showResult(registerResult, `Verification code sent! For testing, use: ${response.body.verification_code}`, "ok");

                        // Show verification form
                        registerForm.style.display = 'none';
                        verifyForm.style.display = 'block';
                    });

                    verifyForm.addEventListener("submit", async (event) => {
                        event.preventDefault();

                        const code = document.getElementById("verify-code").value.trim();

                        if (code === '') {
                            showResult(verifyResult, "Please enter the verification code.", "warn");
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
    </body>

    </html>
<?php
    exit;
}

if ($path === '/ping') {
    // Ping page
?>
    <!doctype html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Send Peace Ping - Peace Ping</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="styles.css?v=<?= time() ?>">
    </head>

    <body>
        <header class="header">
            <nav class="nav">
                <div class="nav-brand">
                    <h1>Peace Ping</h1>
                </div>
                <ul class="nav-links">
                    <li><a href="/">Home</a></li>
                    <li><a href="/how-it-works">How It Works</a></li>
                    <li><a href="/ping" class="active">Send Ping</a></li>
                    <li><a href="/contact">Contact</a></li>
                </ul>
            </nav>
        </header>

        <main class="ping-page">
            <div class="container">
                <section class="page-header">
                    <h1>Send a Peace Ping</h1>
                    <p>Take the first step towards reconnection</p>
                </section>

                <section class="grid">
                    <article class="card">
                        <h2>📤 Send Your Peace Ping</h2>
                        <form id="ping-form">
                            <div class="form-group">
                                <label for="ping-target">Their Mobile Number</label>
                                <input id="ping-target" name="target" type="tel" required placeholder="07xxx xxxxxx">
                                <small>Format: 07xxx xxxxxx or +447xx xxxxxx</small>
                            </div>
                            <button type="submit">Send Peace Ping</button>
                        </form>
                        <p id="ping-result" class="result" aria-live="polite"></p>
                        <div id="register-prompt" class="result" style="display: none;">
                            <p>You need to <a href="/register">register and verify your account</a> before sending Peace Pings.</p>
                        </div>
                    </article>

                    <article id="match-info" class="card" hidden>
                        <h2>✨ Peace Ping Matched!</h2>
                        <p id="match-message"></p>
                        <div id="next-steps">
                            <h3>What happens next?</h3>
                            <p>Both of you will receive SMS messages with questions to help make the reconnection comfortable for everyone.</p>
                        </div>
                    </article>
                </section>
            </div>
        </main>

        <footer class="footer">
            <div class="container">
                <p>&copy; 2024 Peace Ping. Reconnecting people with peace of mind.</p>
            </div>
        </footer>

        <script src="app.js" defer></script>
        <script>
            // Check if user is logged in when page loads
            document.addEventListener('DOMContentLoaded', function() {
                checkLoginStatus();
            });

            async function checkLoginStatus() {
                try {
                    const response = await fetch('api/register', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'status'
                        })
                    });

                    const result = await response.json();

                    if (!result.logged_in) {
                        document.getElementById('register-prompt').style.display = 'block';
                        document.getElementById('ping-form').style.display = 'none';
                    } else {
                        // Show user info
                        const userInfo = document.createElement('div');
                        userInfo.className = 'user-info';
                        userInfo.innerHTML = `<p>Logged in as: <strong>${result.user.name}</strong></p>`;
                        document.querySelector('.card h2').after(userInfo);
                    }
                } catch (error) {
                    console.error('Error checking login status:', error);
                    document.getElementById('register-prompt').style.display = 'block';
                }
            }
        </script>
    </body>

    </html>
<?php
    exit;
}

if ($path === '/contact') {
    // Contact page
?>
    <!doctype html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Contact Us - Peace Ping</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="styles.css?v=<?= time() ?>">
    </head>

    <body>
        <header class="header">
            <nav class="nav">
                <div class="nav-brand">
                    <h1>Peace Ping</h1>
                </div>
                <ul class="nav-links">
                    <li><a href="/">Home</a></li>
                    <li><a href="/how-it-works">How It Works</a></li>
                    <li><a href="/ping">Send Ping</a></li>
                    <li><a href="/contact" class="active">Contact</a></li>
                </ul>
            </nav>
        </header>

        <main class="contact-page">
            <div class="container">
                <section class="page-header">
                    <h1>Contact Us</h1>
                    <p>Have questions? We're here to help.</p>
                </section>

                <section class="contact-info">
                    <div class="contact-methods">
                        <div class="contact-method">
                            <h3>📧 Email</h3>
                            <p>support@peaceping.com</p>
                            <p>We'll respond within 24 hours</p>
                        </div>
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
                    </div>
                </section>

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
                </section>
            </div>
        </main>

        <footer class="footer">
            <div class="container">
                <p>&copy; 2024 Peace Ping. Reconnecting people with peace of mind.</p>
            </div>
        </footer>
    </body>

    </html>
<?php
    exit;
}

// 404 page
Response::json(['error' => 'Page not found.'], 404);
?>