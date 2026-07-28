<?php
/**
 * Update Category
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'Invalid request method');
}

$category_id = $_GET['category_id'] ?? null;
if (!$category_id) {
    sendResponse(false, 'Category ID is required');
}

$conn = getDBConnection();
$data = getJSONInput();

try {
    $updates = [];
    $types = "";
    $values = [];
    
    $allowed_fields = ['category_name', 'description'];
    
    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $values[] = $data[$field];
            $types .= 's';
        }
    }
    
    if (empty($updates)) {
        sendResponse(false, 'No fields to update');
    }
    
    $sql = "UPDATE categories SET " . implode(', ', $updates) . " WHERE category_id = ?";
    $types .= 'i';
    $values[] = $category_id;
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    sendResponse(true, 'Category updated successfully');
    
} catch (Exception $e) {
    logError('Update category error: ' . $e->getMessage());
    sendResponse(false, 'Failed to update category');
}



?>
