<?php
// Preferences page for Stage 3 of Peace Ping
require_once __DIR__ . '/src/bootstrap.php';

use App\Database\Database;
use App\Services\PeacePingService;
use App\Services\UserService;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Utils\Encryption;

// Initialize services
$db = Database::getConnection($config['db']);
$encryption = new Encryption($config['security']['encryption_key'] ?? '');
$smsService = new SmsService($config);
$notificationService = new NotificationService($smsService, $encryption);
$userService = new UserService($db, new \App\Fingerprint(), $encryption, $notificationService, $config['security']['pepper']);
$peacePingService = new PeacePingService($db, new \App\Fingerprint(), $userService, $notificationService, $config['security']['pepper']);

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
            <h1>🕊️ Peace Ping Match!</h1>
            <p>Someone you're thinking about is also thinking about you. Please share your preferences for reconnecting.</p>
        </div>

        <?php if ($success): ?>
            <div class="success-message">
                <h3>✅ Thank You!</h3>
                <p><?php echo htmlspecialchars($success); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message">
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