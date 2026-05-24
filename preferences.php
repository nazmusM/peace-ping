<?php
// Preferences page for Stage 3 of Peace Ping process
require_once __DIR__ . '/src/bootstrap.php';

use App\Database\Database;
use App\Services\SmsService;
use App\Utils\Encryption;
use App\Services\NotificationService;
use App\Services\UserService;
use App\Services\PeacePingService;

// Initialize services
$db = Database::getConnection($config['db']);
$encryption = new Encryption($config['security']['encryption_key'] ?? '');
$smsService = new SmsService($config);
$notificationService = new NotificationService($smsService, $encryption);
$userService = new UserService($db, new \App\Fingerprint(), $encryption, $notificationService, $config['security']['pepper']);
$peacePingService = new PeacePingService(
    $db,
    new \App\Fingerprint(),
    $userService,
    $notificationService,
    $config['security']['pepper'],
    $config['notifications']['portal_url'] ?? ''
);

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

// On GET, check if preference was already submitted to show proper message
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $token !== '') {
    $tokenCheck = $db->prepare(
        'SELECT mt.is_used, mt.expires_at FROM match_tokens mt WHERE mt.token = ? LIMIT 1'
    );
    $tokenCheck->bind_param('s', $token);
    $tokenCheck->execute();
    $tokenRow = $tokenCheck->get_result()->fetch_assoc();
    $tokenCheck->close();

    if ($tokenRow && (int) $tokenRow['is_used'] === 1) {
        $success = 'Your preference has already been recorded. The final update will appear on your dashboard once both people have responded.';
    } elseif ($tokenRow && strtotime((string) $tokenRow['expires_at']) <= time()) {
        $error = 'This preference link has expired.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Peace Ping Preferences - Share your reconnection preferences">
    <title>Peace Ping - Preferences</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <style>
        .preference-card {
            background: var(--panel);
            border: 2px solid var(--line);
            border-radius: var(--radius);
            padding: var(--space-xl);
            margin-bottom: var(--space-lg);
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .preference-card:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .preference-card.selected {
            border-color: var(--accent);
            background: var(--accent-light);
        }

        .preference-card.selected::after {
            content: "✓";
            position: absolute;
            top: var(--space-md);
            right: var(--space-md);
            background: var(--accent);
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .preference-title {
            font-size: var(--font-xl);
            font-weight: 600;
            margin-bottom: var(--space-sm);
            color: var(--ink);
        }

        .preference-description {
            color: var(--muted);
            line-height: 1.6;
        }

        .preference-icon {
            font-size: var(--font-3xl);
            margin-bottom: var(--space-md);
            display: block;
        }

        .form-container {
            max-width: 600px;
            margin: 0 auto;
        }

        .submit-btn {
            width: 100%;
            padding: var(--space-lg);
            font-size: var(--font-lg);
            margin-top: var(--space-xl);
        }

        .success-message {
            background: rgba(5, 150, 105, 0.1);
            color: var(--ok);
            border: 1px solid rgba(5, 150, 105, 0.2);
            padding: var(--space-lg);
            border-radius: var(--radius);
            text-align: center;
            margin-bottom: var(--space-lg);
        }

        .error-message {
            background: rgba(217, 119, 6, 0.1);
            color: var(--warn);
            border: 1px solid rgba(217, 119, 6, 0.2);
            padding: var(--space-lg);
            border-radius: var(--radius);
            text-align: center;
            margin-bottom: var(--space-lg);
        }

        @media (max-width: 768px) {
            .preference-card {
                padding: var(--space-lg);
            }

            .preference-icon {
                font-size: var(--font-2xl);
            }

            .preference-title {
                font-size: var(--font-lg);
            }
        }
    </style>
</head>

<body>
    <header class="header">
        <nav class="nav">
            <div class="nav-brand">
                <h1>Peace Ping</h1>
            </div>
        </nav>
    </header>

    <main class="container" style="padding: var(--space-xl) var(--space-md);">
        <div class="page-header">
            <h1>Peace Ping Private Update</h1>
            <p>Please share your private preference for how you would like this to proceed.</p>
        </div>

        <?php if ($success): ?>
            <div class="success-message">
                <h3><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="8 12 11 15 16 9"/></svg> Thank You!</h3>
                <p><?php echo htmlspecialchars($success); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message">
                <h3><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg> Error</h3>
                <p><?php echo htmlspecialchars($error); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!$success && !$error): ?>
            <div class="form-container">
                <form method="POST" id="preference-form">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                    <div class="preference-card" data-preference="comfortable">
                        <span class="preference-icon"><svg class="icon icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l-2 2c-1 1-1 2 0 3l2 2c1 1 2 1 3 0l2-2"/><path d="M18 15l2 2c1 1 1 2 0 3l-2 2c-1 1-2 1-3 0l-2-2"/><path d="M8 7l-3 3 5 5 3-3"/><path d="M16 7l3 3-5 5-3-3"/><path d="M12 4v3"/><path d="M9 7h6"/></svg></span>
                        <div class="preference-title">I'm comfortable reaching out</div>
                        <div class="preference-description">
                            I'm happy to make the first move and reconnect directly. I feel confident about reaching out to reestablish our connection.
                        </div>
                    </div>

                    <div class="preference-card" data-preference="prefer_other">
                        <span class="preference-icon"><svg class="icon icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21l-4-4c-2-2-3-5-1-8l2-2c1-1 2-1 3 0l2 2c2 3 1 6-1 8l-4 4"/><path d="M7 11l3 3"/><path d="M17 11l-3 3"/></svg></span>
                        <div class="preference-title">I prefer the other person to reach out first</div>
                        <div class="preference-description">
                            I'd be more comfortable if the other person initiates the reconnection. I'm open to reconnecting but prefer they take the lead.
                        </div>
                    </div>

                    <div class="preference-card" data-preference="either">
                        <span class="preference-icon"><svg class="icon icon-lg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span>
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
            <h3><svg class="icon icon-md" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><circle cx="12" cy="16" r="1.5"/><path d="M8 11V7a4 4 0 018 0v4"/></svg> Your Privacy Matters</h3>
            <p>Your preference is completely private. It is only used to decide how Peace Ping describes your reconnection. The other person will never see your specific choice.</p>

            <div style="margin-top: var(--space-lg);">
                <h4>What happens next?</h4>
                <ul style="color: var(--muted); line-height: 1.8;">
                    <li>Both people share their preferences privately</li>
                    <li>We decide the best approach based on what you both prefer</li>
                    <li>You will both receive a final message with guidance for reconnection</li>
                    <li>The final message respects what you both feel comfortable with</li>
                </ul>
            </div>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2024 Peace Ping. Reconnecting people thoughtfully.</p>
        </div>
    </footer>

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
</body>

</html>
