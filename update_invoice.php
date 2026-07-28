<?php
/**
 * Update Invoice
 */

require_once 'config.php';
require_once 'notification_helper.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'])) {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['invoice_id', 'store_id']);

try {
    $currentStmt = $conn->prepare("
        SELECT invoice_id, invoice_number, status, due_date
        FROM invoices
        WHERE invoice_id = ? AND store_id = ?
        LIMIT 1
    ");
    // PDO bind
$currentStmt->execute([$data['invoice_id'], $data['store_id']]);$currentStmt->execute();
    $currentInvoice = $currentStmt->fetch(PDO::FETCH_ASSOC);
    

    if (!$currentInvoice) {
        sendResponse(false, 'Invoice not found');
    }

    // Build update query dynamically based on provided fields
    $updates = [];
    $params = [];
    $types = "";
    
    // Define allowed fields for update
    $allowed_fields = ['status', 'due_date', 'subtotal', 'tax', 'discount', 'total', 'notes'];
    
    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = $data[$field];
            
            // Determine parameter type without nested ternary
            if (in_array($field, ['subtotal', 'tax', 'discount', 'total'])) {
                $types .= "d"; // decimal
            } else {
                $types .= "s"; // string
            }
        }
    }
    
    if (empty($updates)) {
        sendResponse(false, 'No fields to update');
    }
    
    // Add invoice_id and store_id to params for WHERE clause
    $params[] = $data['invoice_id'];
    $params[] = $data['store_id'];
    $types .= "ii";
    
    $sql = "UPDATE invoices SET " . implode(", ", $updates) . " WHERE invoice_id = ? AND store_id = ?";
    
    $stmt = $conn->prepare($sql);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $newStatus = $data['status'] ?? $currentInvoice['status'];
            if (isset($data['status']) && $newStatus !== $currentInvoice['status']) {
                try {
                    notifyInvoiceStatusUpdate(
                        $conn,
                        (int)$data['store_id'],
                        $data['user_id'] ?? null,
                        (int)$data['invoice_id'],
                        $currentInvoice['invoice_number'],
                        $newStatus,
                        $data['due_date'] ?? $currentInvoice['due_date']
                    );
                } catch (Throwable $notifyError) {
                    logError('Invoice status notification error: ' . $notifyError->getMessage());
                }
            }
            
            
            sendResponse(true, 'Invoice updated successfully');
        } else {
            
            
            sendResponse(false, 'Invoice not found or no changes made');
        }
    } else {
        
        
        sendResponse(false, 'Failed to update invoice');
    }
    
} catch (Exception $e) {
    if (isset($stmt)) {
        
    }
    if (isset($conn)) {
        
    }
    logError('Update invoice error: ' . $e->getMessage());
    sendResponse(false, 'Failed to update invoice: ' . $e->getMessage());
}
?>
