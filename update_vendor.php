<?php
/**
 * Update Vendor
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Invalid request method');
}

$vendor_id = $_GET['vendor_id'] ?? null;
if (!$vendor_id) {
    sendResponse(false, 'Vendor ID is required');
}

$conn = getDBConnection();
$data = getJSONInput();

try {
    $updates = [];
    $types = "";
    $values = [];
    
    $allowed_fields = ['supplier_name', 'contact_person', 'email', 'phone', 
                       'address', 'payment_terms', 'payment_days', 'status'];
    $integer_fields = ['payment_days'];
    
    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            if (in_array($field, $integer_fields)) {
                $values[] = (int)$data[$field];
                $types .= 'i';
            } else {
                $values[] = $data[$field];
                $types .= 's';
            }
        }
    }
    
    if (empty($updates)) {
        sendResponse(false, 'No fields to update');
    }
    
    $sql = "UPDATE suppliers SET " . implode(', ', $updates) . " WHERE supplier_id = ?";
    $types .= 'i';
    $values[] = $vendor_id;
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    sendResponse(true, 'Vendor updated successfully');
    
} catch (Exception $e) {
    logError('Update vendor error: ' . $e->getMessage());
    sendResponse(false, 'Failed to update vendor');
}



?>
