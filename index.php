<?php
/**
 * ============================================
 * AI BLAST TELEGRAM MINI APP - MAIN ENTRY
 * ============================================
 * This file handles initial page load and user sync
 * All other operations are handled via AJAX calls
 */

require_once 'config.php';
require_once 'include/security.php';
require_once 'include/helper.php';
require_once 'include/database.php';

// ============================================
// HANDLE AJAX SYNC USER REQUEST
// ============================================
if (isset($_POST['action']) && $_POST['action'] == 'sync_user') {
    header('Content-Type: application/json');
    
    $tg_id = intval($_POST['id'] ?? 0);
    $initData = $_POST['initData'] ?? '';
    
    // Validate Telegram access
    if (!validateTelegramInitData($initData, BOT_TOKEN)) {
        logData('🚫 Unauthorized sync attempt - Telegram ID: ' . $tg_id);
        sendJsonResponse('error', null, 'Access denied. Please open through Telegram bot.');
    }
    
    logData('✅ Telegram validation passed for user: ' . $tg_id);
    
    // Get user data from POST
    $username = (!empty($_POST['username']) && $_POST['username'] !== 'undefined') ? $_POST['username'] : NULL;
    $first_name = sanitizeInput($_POST['first_name'] ?? '');
    $last_name = sanitizeInput($_POST['last_name'] ?? '');
    $lang = sanitizeInput($_POST['language_code'] ?? 'en');
    $is_premium = (isset($_POST['is_premium']) && $_POST['is_premium'] == 'true') ? 1 : 0;
    $sponsor_param = sanitizeInput($_POST['start_param'] ?? '');
    
    try {
        // Check if user exists
        $user = getUserByTelegramId($tg_id);
        
        if ($user) {
            // Existing user - check if registration is complete
            if ($user['sponser_id'] > 0) {
                // Complete registration - update info and return data
                updateUser($tg_id, [
                    'username' => $username,
                    'first_name' => $first_name,
                    'last_name' => $last_name
                ]);
                
                $memberId = $user['member_id'];
                
                // Get statistics
                $totalInvest = getTotalInvestment($memberId);
                $referralIncome = getReferralIncome($memberId);
                $levelIncome = getLevelIncome($memberId);
                $roiIncome = getROIIncome($memberId);
                $dailyEarnings = calculateDailyROI($totalInvest);
                
                sendJsonResponse('success', [
                    'user_id' => $user['user_id'],
                    'telegram_id' => $user['telegram_id'],
                    'member_id' => $memberId,
                    'balance' => formatCurrency($user['incomeWallet']),
                    'fundWallet' => formatCurrency($user['fundWallet']),
                    'refferalWallet' => formatCurrency($user['refferalWallet']),
                    'walletAddress' => $user['walletAddress'] ?? '',
                    'totalInvest' => formatCurrency($totalInvest),
                    'dailyEarnings' => formatCurrency($dailyEarnings),
                    'dailyReturnPercentage' => DAILY_ROI_PERCENTAGE,
                    'referralIncome' => formatCurrency($referralIncome),
                    'levelIncome' => formatCurrency($levelIncome),
                    'roiIncome' => formatCurrency($roiIncome)
                ]);
            }
        }
        
        // New user or incomplete registration - require sponsor
        if (empty($sponsor_param)) {
            logData('🚫 Registration blocked: No sponsor for telegram ID ' . $tg_id);
            sendJsonResponse('error', null, 'Registration requires a valid referral link. Please join via sponsor.');
        }
        
        // Validate sponsor
        $stmt = $conn->prepare('SELECT member_id FROM users WHERE user_id = ?');
        $stmt->bind_param('s', $sponsor_param);
        $stmt->execute();
        $sponsorResult = $stmt->get_result();
        
        if ($sponsorResult->num_rows === 0) {
            logData('🚫 Invalid sponsor code: ' . $sponsor_param);
            sendJsonResponse('error', null, 'The referral link used is invalid or expired.');
        }
        
        $sponsorData = $sponsorResult->fetch_assoc();
        $sponsorMemberId = $sponsorData['member_id'];
        
        // Generate unique user ID
        do {
            $new_user_id = generateUserID();
            $idCheck = $conn->prepare("SELECT member_id FROM users WHERE user_id = ?");
            $idCheck->bind_param('s', $new_user_id);
            $idCheck->execute();
            $exists = $idCheck->get_result()->num_rows > 0;
        } while ($exists);
        
        // Create user
        $userData = [
            'user_id' => $new_user_id,
            'telegram_id' => $tg_id,
            'sponser_id' => $sponsorMemberId,
            'username' => $username,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'language_code' => $lang,
            'is_premium' => $is_premium
        ];
        
        $memberId = createUser($userData);
        
        if ($memberId <= 0) {
            logData('❌ Failed to create user for telegram ID ' . $tg_id);
            sendJsonResponse('error', null, 'Registration failed. Please try again.');
        }
        
        // Build MLM tree
        // buildMLMTree($memberId, $sponsorMemberId);
        
        logData("✅ New user registered: Member ID $memberId, Sponsor ID $sponsorMemberId");
        
        sendJsonResponse('success', [
            'user_id' => $new_user_id,
            'member_id' => $memberId,
            'balance' => '0.00',
            'fundWallet' => '0.00',
            'refferalWallet' => '0.00',
            'walletAddress' => '',
            'totalInvest' => '0.00',
            'dailyEarnings' => '0.00',
            'dailyReturnPercentage' => DAILY_ROI_PERCENTAGE,
            'referralIncome' => '0.00',
            'levelIncome' => '0.00',
            'roiIncome' => '0.00'
        ], 'Registration successful!');
        
    } catch (Exception $e) {
        logData('❌ Error in sync_user: ' . $e->getMessage(), 'error');
        sendJsonResponse('error', null, 'An error occurred. Please try again.');
    }
}

// ============================================
// HTML OUTPUT - INITIAL PAGE LOAD
// ============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Blast - Crypto Trading Bot</title>
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- QR Code Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    
    <!-- Styles -->
    <link rel="stylesheet" href="assets/css/styles.css">
    
    <!-- Telegram WebApp SDK -->
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
    <div class="app-container">
        <!-- Header -->
        <div class="header">
            <div class="earned-section">
                <span class="earned-label">You Earned:</span>
                <span class="earned-value" id="totalEarned">0.00</span>
                <div class="coin-badge">₮</div>
            </div>
            <button class="withdraw-btn" onclick="showPage('withdraw')">
                Withdraw →
            </button>
        </div>

        <!-- Home Page -->
        <div id="page-home" class="content-page active">
            <!-- Blast Card -->
            <div class="blast-card">
                <div class="ai-blast-label">Ai Blast</div>
                <div class="blast-power-text">Blast Power</div>
                <div class="blast-amount">
                    $ <span id="blastPower">0.00</span>
                    <div class="ai-icon">Ai</div>
                </div>
                <div class="daily-earn-text">
                    Daily Earn: <span class="amount">$<span id="dailyEarn">0.00</span></span>
                </div>
                <div class="percentage-pill">
                    ↗ <span id="percentage">2 %</span>
                </div>
                <div class="settlement-row">
                    🕐 Settlement time: <span id="countdown">18:17:14</span>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-grid">
                <button class="action-card primary" id="buyBpBtn">
                    <span class="action-icon">+</span>
                    <div class="action-label">Buy BP</div>
                </button>
                <button class="action-card" onclick="showPage('friends')">
                    <span class="action-icon"><i class="bi bi-people-fill"></i></span>
                    <div class="action-label">Refer & Earn</div>
                </button>
                <button class="action-card" onclick="showPage('history')">
                    <span class="action-icon"><i class="bi bi-clock-history"></i></span>
                    <div class="action-label">History</div>
                </button>
                <button id="refreshBtn" class="action-card">
                    <span class="action-icon">
                        <i class="bi bi-arrow-repeat" id="refreshIcon"></i>
                    </span>
                    <div class="action-label">Refresh</div>
                </button>
            </div>

            <!-- Income Cards -->
            <div class="income-grid">
                <div class="income-card green">
                    <div class="income-label">Referral<br>Income</div>
                    <div class="income-amount">$ 0.00</div>
                </div>
                <div class="income-card orange">
                    <div class="income-label">Roi<br>Income</div>
                    <div class="income-amount">$ 0.00</div>
                </div>
                <div class="income-card blue">
                    <div class="income-label">Level<br>Income</div>
                    <div class="income-amount">$ 0.00</div>
                </div>
            </div>

            <!-- Dashboard Tabs -->
            <div class="dashboard-container">
                <div class="tab-nav">
                    <button class="tab-btn active" onclick="showTab('blast-power')">My Blast Power</button>
                    <button class="tab-btn" onclick="showTab('trading')">Ai Trading Status</button>
                </div>

                <div id="tab-blast-power" class="tab-content">
                    <div class="blast-power-section">
                        <!-- Loaded via PHP or AJAX -->
                    </div>
                </div>

                <div id="tab-trading" class="tab-content" style="display:none;">
                    <div class="trading-section">
                        <!-- Loaded via PHP or AJAX -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Friends/Referral Page -->
        <div id="page-friends" class="content-page">
            <div class="page-title">Friends & Referral</div>
            <div class="referral-container">
                <!-- Referral content -->
            </div>
        </div>

        <!-- History Page -->
        <div id="page-history" class="content-page">
            <!-- History content -->
        </div>

        <!-- Withdraw Page -->
        <div id="page-withdraw" class="content-page">
            <div class="page-title">WithDraw</div>
            <div class="withdraw-container">
                <div class="balance-card">
                    <div class="balance-label">Available balance:</div>
                    <div class="balance-amount">$ <span id="withdrawBalance">0.00</span></div>
                </div>

                <form class="bp-body">
                    <div class="form-group">
                        <label class="form-label">Withdraw Amount</label>
                        <span class="all-amount" id="allAmountBtn">All Amount</span>
                        <input type="text" id="with_amt" class="form-input" placeholder="Min $1 USDT">
                    </div>

                    <div class="form-group">
                        <label class="form-label">BSC-USDT Receiving address</label>
                        <input type="text" class="form-input" id="with_add" placeholder="0x...">
                    </div>

                    <button class="continue-btn" type="button" id="withdrawalbut">Withdrawal</button>
                </form>
            </div>
        </div>

        <!-- Bottom Navigation -->
        <div class="bottom-nav">
            <div class="nav-item active" onclick="showPage('home')">
                <div class="ai-logo">Ai</div>
                <span>Ai</span>
            </div>
            <div class="nav-item" onclick="showPage('friends')">
                <span><i class="bi bi-person-plus"></i></span>
                <span>Friends</span>
            </div>
            <div class="nav-item" onclick="showPage('intro')">
                <span><i class="bi bi-info-circle"></i></span>
                <span>Intro</span>
            </div>
            <div class="nav-item" onclick="showPage('task')">
                <span><i class="bi bi-list-check"></i></span>
                <span>Task</span>
            </div>
        </div>
    </div>

    <!-- Buy BP Modal -->
    <div class="bp-overlay" id="bpOverlay"></div>
    <div class="bp-modal" id="bpModal">
        <!-- Step 1: Investment Calculator -->
        <div class="bp-body" id="investcalculate">
            <div class="bp-header">
                <h3>Enter the quantity you want to buy</h3>
                <span class="close-btn" id="closeBp">&times;</span>
            </div>

            <div class="bp-input">
                <span class="ai-icon1">✨AI</span>
                <input type="number" id="amountInput" min="5" placeholder="Min $ 5 USDT">
            </div>

            <div class="bp-total">
                Your BP total will be: <strong><span id="tpTotal">0.00</span> USDT</strong>
            </div>

            <div class="estimate-title">Estimated return (2% Daily):</div>

            <div class="estimate-row">
                <div class="estimate-box">
                    <div class="est-label">Daily</div>
                    <div class="est-value" id="dailyReturn">0.00</div>
                </div>
                <div class="estimate-box">
                    <div class="est-label">20 Days</div>
                    <div class="est-value"><span id="days20Return">0.00</span></div>
                </div>
                <div class="estimate-box">
                    <div class="est-label">100 Days</div>
                    <div class="est-value"><span id="profitReturn">0.00</span></div>
                </div>
            </div>

            <button type="button" id="deposit_btn" class="continue-btn">Continue</button>
        </div>

        <!-- Step 2: Payment Section -->
        <div class="bp-body" id="investment" style="display:none;">
            <h4 class="pay-title">
                <span>Send countdown:</span>
                <span id="countdownId" style="color:#ff7f02;">30:00</span>
            </h4>

            <div class="pay-amount">
                Amount: <strong id="investamount">$0.00 USDT</strong>
            </div>

            <div class="qr-box">
                <canvas id="qrCanvas"></canvas>
            </div>

            <div class="wallet-box-container">
                <div class="wallet-box">
                    <span id="walletAddress"></span>
                    <img id="copyBtn" src="assets/images/copy.png" alt="Copy" />
                </div>
            </div>

            <div class="payment-warning">
                * Don't send non-BEP20-USDT assets
            </div>

            <div id="paymentStatusText" class="payment-status">
                Payment Status: Waiting... 
                <img id="refreshIcon2" src="assets/images/refresh-icon.png" class="spin-animation" />
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast"></div>

    <!-- JavaScript -->
    <script src="assets/js/ajax-handler.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>