<?php
/**
 * Update Sale
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Invalid request method');
}

$sale_id = $_GET['sale_id'] ?? null;
if (!$sale_id) {
    sendResponse(false, 'Sale ID is required');
}

$conn = getDBConnection();
$data = getJSONInput();

try {
    $updates = [];
    $types = "";
    $values = [];
    
    $allowed_fields = ['customer_id', 'invoice_number', 'sale_date',
                       'tax_amount', 'discount_amount', 'final_amount', 
                       'payment_method', 'payment_status', 'notes'];
    
    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $values[] = $data[$field];
            $types .= (in_array($field, ['tax_amount', 'discount_amount', 'final_amount'])) ? 'd' : 
                     (in_array($field, ['customer_id'])) ? 'i' : 's';
        }
    }
    
    if (empty($updates)) {
        sendResponse(false, 'No fields to update');
    }
    
    $sql = "UPDATE sales SET " . implode(', ', $updates) . " WHERE sale_id = ?";
    $types .= 'i';
    $values[] = $sale_id;
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    sendResponse(true, 'Sale updated successfully');
    
} catch (Exception $e) {
    logError('Update sale error: ' . $e->getMessage());
    sendResponse(false, 'Failed to update sale');
}



?>
