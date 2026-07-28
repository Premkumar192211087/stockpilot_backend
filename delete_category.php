<?php
/**
 * Delete Category
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    sendResponse(false, 'Invalid request method');
}

$category_id = $_GET['category_id'] ?? null;
if (!$category_id) {
    sendResponse(false, 'Category ID is required');
}

$conn = getDBConnection();

try {
    $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
    // PDO bind
$stmt->execute([$category_id]);$stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        sendResponse(true, 'Category deleted successfully');
    } else {
        sendResponse(false, 'Category not found');
    }
    
} catch (Exception $e) {
    logError('Delete category error: ' . $e->getMessage());
    sendResponse(false, 'Failed to delete category');
}



?>
