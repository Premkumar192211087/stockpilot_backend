<?php
/**
 * Register or Update FCM Token
 * Stores device FCM token for push notifications
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['user_id', 'store_id', 'token']);

$user_id = (int)$data['user_id'];
$store_id = (int)$data['store_id'];
$token = trim((string)$data['token']);
$device_info = isset($data['device_info']) ? trim((string)$data['device_info']) : null;

if ($user_id <= 0 || $store_id <= 0 || $token === '') {
    sendResponse(false, 'Invalid user, store, or token');
}

try {
    $conn->beginTransaction();

    // Check if token already exists on any user/device session.
    $checkStmt = $conn->prepare("SELECT id FROM fcm_tokens WHERE token = ? LIMIT 1");
    // PDO bind
$checkStmt->execute([$token]);$checkStmt->execute();
    // PDO result ready in $checkStmt
    $result = $checkStmt;
    $existing = $result->fetch(PDO::FETCH_ASSOC);
    

    if ($existing) {
        $updateStmt = $conn->prepare("
            UPDATE fcm_tokens
            SET user_id = ?,
                store_id = ?,
                device_info = ?,
                is_active = 1,
                updated_at = NOW()
            WHERE id = ?
        ");
        // PDO bind
$updateStmt->execute([$user_id, $store_id, $device_info, $existing['id']]);$updateStmt->execute();
        $token_id = (int)$existing['id'];
        $action = 'updated';
        
    } else {
        $insertStmt = $conn->prepare("
            INSERT INTO fcm_tokens (user_id, store_id, token, device_info, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        // PDO bind
$insertStmt->execute([$user_id, $store_id, $token, $device_info]);$insertStmt->execute();
        $token_id = $conn->lastInsertId();
        $action = 'created';
        
    }

    // Keep only the current device token active for this user/store to avoid slow duplicate FCM sends.
    $activeStmt = $conn->prepare("
        UPDATE fcm_tokens
        SET is_active = 0, updated_at = NOW()
        WHERE user_id = ? AND store_id = ? AND token <> ?
    ");
    // PDO bind
$activeStmt->execute([$user_id, $store_id, $token]);$activeStmt->execute();
    

    $conn->commit();

    sendResponse(true, 'FCM token registered successfully', [
        'token_id' => $token_id,
        'action' => $action,
    ]);
} catch (Exception $e) {
    $conn->rollBack();
    logError('Register FCM token error: ' . $e->getMessage());
    sendResponse(false, 'Failed to register FCM token');
}


?>


