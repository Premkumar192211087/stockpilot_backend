<?php
/**
 * Delete Account Endpoint
 * Deletes user account and optionally all related store data
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['user_id', 'store_id', 'password']);

try {
    // Verify password first
    $stmt = $conn->prepare("SELECT password, role FROM users WHERE user_id = ? AND store_id = ?");
    // PDO bind
$stmt->execute([$data['user_id'], $data['store_id']]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;

    if ($result->num_rows === 0) {
        sendResponse(false, 'User not found');
    }

    $user = $result->fetch(PDO::FETCH_ASSOC);
    if ($data['password'] !== $user['password']) {
        sendResponse(false, 'Incorrect password');
    }
    

    $conn->beginTransaction();

    // If admin and delete_store flag is set, delete all store data
    if ($user['role'] === 'admin' && isset($data['delete_store']) && $data['delete_store']) {
        // Delete all store data from existing tables
        $tables = ['notifications', 'fcm_tokens', 'payments',
                   'sale_items', 'sales', 'batch_details',
                   'return_items', 'returns', 'purchase_order_items',
                   'purchase_orders', 'invoices', 'shipments',
                   'damages', 'products', 'customers', 'suppliers',
                   'categories', 'item_group_members', 'item_groups',
                   'inventory_alerts', 'reports', 'audit_log', 'users'];
        
        foreach ($tables as $table) {
            $stmt = $conn->prepare("DELETE FROM $table WHERE store_id = ?");
            // PDO bind
$stmt->execute([$data['store_id']]);$stmt->execute();
            
        }
    } else {
        // Just delete this user
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        // PDO bind
$stmt->execute([$data['user_id']]);$stmt->execute();
        
    }

    $conn->commit();
    sendResponse(true, 'Account deleted successfully');

} catch (Exception $e) {
    $conn->rollBack();
    logError('Delete account error: ' . $e->getMessage());
    sendResponse(false, 'Failed to delete account');
}


?>
