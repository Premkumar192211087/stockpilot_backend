<?php
/**
 * Add Customer
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['customer_name', 'phone', 'store_id']);

try {
    // Check if date_of_birth and loyalty_points columns exist
    $columns_check = $conn->query("SHOW COLUMNS FROM customers LIKE 'date_of_birth'");
    $has_dob = $columns_check->num_rows > 0;
    
    $columns_check = $conn->query("SHOW COLUMNS FROM customers LIKE 'loyalty_points'");
    $has_loyalty = $columns_check->num_rows > 0;
    
    // Build dynamic INSERT query (customers are global — no store_id column)
    $columns = "customer_name, email, phone, address, status";
    $placeholders = "?, ?, ?, ?, ?";
    $types = "sssss";
    $params = [
        $data['customer_name'],
        $data['email'] ?? null,
        $data['phone'],
        $data['address'] ?? '',
        $data['status'] ?? 'active'
    ];
    
    if ($has_dob && !empty($data['date_of_birth'])) {
        $columns .= ", date_of_birth";
        $placeholders .= ", ?";
        $types .= "s";
        $params[] = $data['date_of_birth'];
    }
    
    if ($has_loyalty) {
        $columns .= ", loyalty_points";
        $placeholders .= ", ?";
        $types .= "i";
        $params[] = isset($data['loyalty_points']) ? intval($data['loyalty_points']) : 0;
    }
    
    $stmt = $conn->prepare("
        INSERT INTO customers ($columns) VALUES ($placeholders)
    ");
    
    $stmt->execute();
    
    $customer_id = $conn->lastInsertId();
    
    // Send notification for new customer
    notifyCustomerAdded(
        $conn,
        $data['store_id'],
        $data['user_id'] ?? 1,
        $customer_id,
        $data['customer_name']
    );
    
    sendResponse(true, 'Customer added successfully', [
        'customer_id' => $customer_id
    ]);
    
} catch (Exception $e) {
    logError('Add customer error: ' . $e->getMessage());
    sendResponse(false, 'Failed to add customer');
}



?>
