<?php
// Match Management System for Peace Ping
require_once __DIR__ . '/src/bootstrap.php';

use App\Database\Database;
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Peace Ping Matches - See who you've matched with">
    <title>Peace Ping - My Matches</title>
    <link rel="stylesheet" href="/styles.css">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <style>
        .match-card {
            background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
            border: 2px solid var(--accent);
            border-radius: var(--radius);
            padding: var(--space-xl);
            margin-bottom: var(--space-lg);
            position: relative;
            overflow: hidden;
        }
        
        .match-card::before {
            content: "✨";
            position: absolute;
            top: var(--space-md);
            right: var(--space-md);
            font-size: var(--font-2xl);
            opacity: 0.7;
        }
        
        .match-status {
            display: inline-block;
            background: var(--ok);
            color: white;
            padding: var(--space-xs) var(--space-sm);
            border-radius: 20px;
            font-size: var(--font-xs);
            font-weight: 600;
            margin-bottom: var(--space-sm);
        }
        
        .match-date {
            color: var(--muted);
            font-size: var(--font-sm);
            margin-bottom: var(--space-md);
        }
        
        .ping-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: var(--space-lg);
            margin-bottom: var(--space-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .ping-status {
            display: inline-block;
            padding: var(--space-xs) var(--space-sm);
            border-radius: 20px;
            font-size: var(--font-xs);
            font-weight: 600;
        }
        
        .ping-status.matched {
            background: rgba(5, 150, 105, 0.1);
            color: var(--ok);
        }
        
        .ping-status.pending {
            background: rgba(217, 119, 6, 0.1);
            color: var(--warn);
        }
        
        .empty-state {
            text-align: center;
            padding: var(--space-2xl);
            color: var(--muted);
        }
        
        .empty-state-icon {
            font-size: var(--font-4xl);
            margin-bottom: var(--space-lg);
            opacity: 0.5;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-lg);
            margin-bottom: var(--space-xl);
        }
        
        .stat-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: var(--space-lg);
            text-align: center;
        }
        
        .stat-number {
            font-size: var(--font-3xl);
            font-weight: 700;
            color: var(--accent);
            margin-bottom: var(--space-sm);
        }
        
        .stat-label {
            color: var(--muted);
            font-size: var(--font-sm);
        }
        
        @media (max-width: 768px) {
            .match-card {
                padding: var(--space-lg);
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: var(--space-md);
            }
            
            .ping-card {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--space-sm);
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
            <ul class="nav-links">
                <li><a href="/">Home</a></li>
                <li><a href="/how-it-works">How It Works</a></li>
                <li><a href="/register">Register & Verify</a></li>
                <li><a href="/ping">Send Ping</a></li>
                <li><a href="/matches" class="active">My Matches</a></li>
                <li><a href="/contact">Contact</a></li>
            </ul>
        </nav>
    </header>

    <main class="container" style="padding: var(--space-xl) var(--space-md);">
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
                                💡 Check your mobile phone for the SMS questions. This is your opportunity to reconnect thoughtfully.
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

        <!-- Help Section -->
        <section class="card" style="margin-top: var(--space-2xl);">
            <h3>🤔 How Peace Ping Works</h3>
            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <div class="step-content">
                        <h4>You send a Peace Ping</h4>
                        <p>Send a ping to someone you're thinking about reconnecting with.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <div class="step-content">
                        <h4>They send a Peace Ping too</h4>
                        <p>If they also send you a ping within a reasonable time, it's a match!</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <div class="step-content">
                        <h4>Both receive SMS questions</h4>
                        <p>You'll both get thoughtful questions to help you reconnect comfortably.</p>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2024 Peace Ping. Reconnecting people thoughtfully.</p>
        </div>
    </footer>
</body>
</html>
