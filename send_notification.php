<?php
/**
 * Send FCM Notification - Using FCM v1 API
 * Sends push notification via Firebase Cloud Messaging v1 API
 * Uses Service Account authentication with OAuth 2.0
 */

require_once 'config.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    handleSendNotificationRequest();
}

function handleSendNotificationRequest() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method');
    }

    $conn = getDBConnection();
    $data = getJSONInput();

    validateRequired($data, ['store_id', 'title', 'message', 'type']);

    $store_id = $data['store_id'];
    $user_id = $data['user_id'] ?? null; // Null = broadcast to all store users
    $title = $data['title'];
    $message = $data['message'];
    $type = normalizeNotificationType($data['type']); // low_stock, expiry, sale, return, shipment, customer, invoice, purchase_order, system
    $notification_data = $data['data'] ?? null; // Extra data for deep linking

    try {
        // Store notification in database
        $insertStmt = $conn->prepare("
            INSERT INTO notifications (store_id, user_id, title, message, type, status, data)
            VALUES (?, ?, ?, ?, ?, 'unread', ?)
        ");
        $data_json = $notification_data ? json_encode($notification_data) : null;
        // PDO bind
$insertStmt->execute([$store_id, $user_id, $title, $message, $type, $data_json]);$insertStmt->execute();
        $notification_id = $conn->lastInsertId();
        
        
        // Get FCM tokens for target users
        if ($user_id) {
            // Send to specific user
            $tokenStmt = $conn->prepare("
                SELECT token FROM fcm_tokens
                WHERE user_id = ? AND store_id = ? AND is_active = 1
                ORDER BY updated_at DESC LIMIT 1
            ");
            // PDO bind
$tokenStmt->execute([$user_id, $store_id]);} else {
            // Broadcast to all users in store
            $tokenStmt = $conn->prepare("
                SELECT ft.token FROM fcm_tokens ft
                INNER JOIN (
                    SELECT user_id, MAX(updated_at) AS updated_at
                    FROM fcm_tokens
                    WHERE store_id = ? AND is_active = 1
                    GROUP BY user_id
                ) latest ON latest.user_id = ft.user_id AND latest.updated_at = ft.updated_at
                WHERE ft.store_id = ? AND ft.is_active = 1
            ");
            // PDO bind
$tokenStmt->execute([$store_id, $store_id]);}
        
        $tokenStmt->execute();
        // PDO result ready in $tokenStmt
    $tokenResult = $tokenStmt;
        $tokens = [];
        while ($row = $tokenResult->fetch(PDO::FETCH_ASSOC)) {
            $tokens[] = $row['token'];
        }
        
        
        if (empty($tokens)) {
            sendResponse(true, 'Notification saved but no active devices to send to', [
                'notification_id' => $notification_id,
                'tokens_count' => 0
            ]);
        }
        
        // Send FCM notification using v1 API
        $fcm_success_count = 0;
        $fcm_failure_count = 0;
        
        foreach ($tokens as $token) {
            $result = sendFCMv1Notification($token, $title, $message, $type, $notification_data);
            if ($result['success']) {
                $fcm_success_count++;
            } else {
                $fcm_failure_count++;
                logError('FCM send failed for token: ' . substr($token, 0, 20) . '... - ' . $result['error']);
            }
        }
        
        sendResponse(true, 'Notification sent successfully', [
            'notification_id' => $notification_id,
            'tokens_count' => count($tokens),
            'sent_count' => $fcm_success_count,
            'failed_count' => $fcm_failure_count
        ]);
        
    } catch (Exception $e) {
        logError('Send notification error: ' . $e->getMessage());
        sendResponse(false, 'Failed to send notification');
    }

    
}

/**
 * Send FCM notification using FCM v1 API with Service Account
 * @param string $token FCM device token
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type
 * @param array $data Additional data payload
 * @return array Result with success status
 */
function sendFCMv1Notification($token, $title, $message, $type, $data = null) {
    $type = normalizeNotificationType($type);
    // Path to your Firebase service account JSON file
    // Download from: Firebase Console > Project Settings > Service Accounts > Generate new private key
    $serviceAccountPath = __DIR__ . '/firebase-service-account.json';
    
    // Check if service account file exists
    if (!file_exists($serviceAccountPath)) {
        return [
            'success' => false, 
            'error' => 'Service account file not found. Please download from Firebase Console.'
        ];
    }
    
    // Load service account credentials
    $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
    if (!$serviceAccount) {
        return ['success' => false, 'error' => 'Invalid service account JSON'];
    }
    
    // Get OAuth 2.0 access token
    $accessToken = getAccessToken($serviceAccount);
    if (!$accessToken) {
        return ['success' => false, 'error' => 'Failed to get access token'];
    }
    
    // Extract project ID from service account
    $projectId = $serviceAccount['project_id'];
    
    // Build FCM v1 message payload
    $fcmMessage = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $message
            ],
            'data' => [
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
            ],
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'sound' => 'default',
                    'channel_id' => getChannelIdForType($type)
                ]
            ]
        ]
    ];
    
    // Add extra data if provided
    if ($data && is_array($data)) {
        foreach ($data as $key => $value) {
            $fcmMessage['message']['data'][$key] = (string)$value;
        }
    }
    
    // FCM v1 API endpoint
    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
    
    // Send via cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fcmMessage));
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => 'HTTP ' . $http_code . ': ' . $response];
    }
}

/**
 * Get OAuth 2.0 access token using JWT assertion
 * @param array $serviceAccount Service account credentials
 * @return string|null Access token or null on failure
 */
function getAccessToken($serviceAccount) {
    // JWT Header
    $header = json_encode([
        'alg' => 'RS256',
        'typ' => 'JWT'
    ]);
    
    // JWT Claims
    $now = time();
    $claims = json_encode([
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600
    ]);
    
    // Encode header and claims
    $base64UrlHeader = base64UrlEncode($header);
    $base64UrlClaims = base64UrlEncode($claims);
    
    // Create signature
    $signatureInput = $base64UrlHeader . '.' . $base64UrlClaims;
    $privateKey = $serviceAccount['private_key'];
    
    openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $base64UrlSignature = base64UrlEncode($signature);
    
    // Create JWT
    $jwt = $signatureInput . '.' . $base64UrlSignature;
    
    // Exchange JWT for access token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $tokenData = json_decode($response, true);
    return $tokenData['access_token'] ?? null;
}

/**
 * Base64 URL encoding (JWT compatible)
 */
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Map notification type to Android notification channel ID
 */
function getChannelIdForType($type) {
    $type = normalizeNotificationType($type);
    $channelMap = [
        'low_stock' => 'channel_inventory',
        'expiry' => 'channel_inventory',
        'adjustment' => 'channel_inventory',
        'order' => 'channel_sales',
        'sale' => 'channel_sales',
        'sales' => 'channel_sales',
        'return' => 'channel_sales',
        'payment' => 'channel_sales',
        'invoice' => 'channel_sales',
        'purchase_order' => 'channel_sales',
        'shipment' => 'channel_sales',
        'customer' => 'general',
        'system' => 'general'
    ];
    
    return $channelMap[$type] ?? 'general';
}

if (!function_exists('normalizeNotificationType')) {
    function normalizeNotificationType($type) {
        $type = strtolower(trim((string)$type));
        if ($type === 'sales') return 'sale';
        return $type;
    }
}
?>



