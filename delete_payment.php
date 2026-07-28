<?php
/**
 * Delete Payment
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$data = $_SERVER['REQUEST_METHOD'] === 'POST' ? getJSONInput() : [];
$payment_id = $_GET['payment_id'] ?? $data['payment_id'] ?? null;
$store_id = $_GET['store_id'] ?? $data['store_id'] ?? null;
if (!$payment_id) {
    sendResponse(false, 'Payment ID is required');
}
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    $stmt = $conn->prepare("DELETE FROM payments WHERE payment_id = ? AND store_id = ?");
    // PDO bind
$stmt->execute([$payment_id, $store_id]);$stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        sendResponse(true, 'Payment deleted successfully');
    } else {
        sendResponse(false, 'Payment not found');
    }
    
} catch (Exception $e) {
    logError('Delete payment error: ' . $e->getMessage());
    sendResponse(false, 'Failed to delete payment');
}

if (isset($stmt)) {
    
}

?>