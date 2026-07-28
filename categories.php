<?php
/**
 * Get Categories
 */

require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    $stmt = $conn->prepare("
        SELECT category_id, category_name, description, created_at
        FROM categories
        WHERE store_id = ?
        ORDER BY category_name ASC
    ");
    
    // PDO bind
$stmt->execute([$store_id]);$stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    
    $categories = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $categories[] = $row;
    }
    
    sendResponse(true, 'Categories retrieved successfully', $categories);
    
} catch (Exception $e) {
    logError('Get categories error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve categories');
}



?>
