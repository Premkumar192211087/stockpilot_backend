<?php
/**
 * Delete Staff
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$data = $_SERVER['REQUEST_METHOD'] === 'POST' ? getJSONInput() : [];
$staff_id = $_GET['staff_id'] ?? $data['staff_id'] ?? null;
$store_id = $_GET['store_id'] ?? $data['store_id'] ?? null;
if (!$staff_id) {
    sendResponse(false, 'Staff ID is required');
}
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    $lookupStmt = $conn->prepare("SELECT user_id FROM staff_details WHERE staff_id = ? AND store_id = ? LIMIT 1");
    // PDO bind
$lookupStmt->execute([$staff_id, $store_id]);$lookupStmt->execute();
    // PDO result ready in $lookupStmt
    $lookupResult = $lookupStmt;
    if ($lookupResult->num_rows === 0) {
        sendResponse(false, 'Staff not found');
    }
    $staff = $lookupResult->fetch(PDO::FETCH_ASSOC);
    $user_id = (int)$staff['user_id'];
    

    $conn->beginTransaction();

    $tokenStmt = $conn->prepare("DELETE FROM fcm_tokens WHERE user_id = ? AND store_id = ?");
    // PDO bind
$tokenStmt->execute([$user_id, $store_id]);$tokenStmt->execute();
    

    $staffStmt = $conn->prepare("DELETE FROM staff_details WHERE staff_id = ? AND store_id = ?");
    // PDO bind
$staffStmt->execute([$staff_id, $store_id]);$staffStmt->execute();
    

    $userStmt = $conn->prepare("DELETE FROM user_login WHERE id = ? AND store_id = ?");
    // PDO bind
$userStmt->execute([$user_id, $store_id]);$userStmt->execute();
    

    $conn->commit();
    sendResponse(true, 'Staff deleted successfully');
    
} catch (Exception $e) {
    $conn->rollBack();
    logError('Delete staff error: ' . $e->getMessage());
    sendResponse(false, 'Failed to delete staff');
}


?>