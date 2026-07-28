<?php
/**
 * Delete Invoice
 */

require_once 'config.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['DELETE', 'POST'])) {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();

$invoice_id = $_GET['invoice_id'] ?? null;
$store_id   = $_GET['store_id']   ?? null;

if (!$invoice_id) {
    sendResponse(false, 'Invoice ID is required');
}

try {
    $stmt = $conn->prepare("DELETE FROM invoices WHERE invoice_id = ? AND store_id = ?");
    // PDO bind
$stmt->execute([$invoice_id, $store_id]);$stmt->execute();

    if ($stmt->affected_rows > 0) {
        
        
        sendResponse(true, 'Invoice deleted successfully');
    } else {
        
        
        sendResponse(false, 'Invoice not found or already deleted');
    }
} catch (Exception $e) {
    logError('Delete invoice error: ' . $e->getMessage());
    sendResponse(false, 'Failed to delete invoice');
}
