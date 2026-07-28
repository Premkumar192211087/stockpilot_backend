<?php
/**
 * Add Category
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['category_name', 'store_id']);

try {
    // Handle optional parent_category_id properly
    if (isset($data['parent_category_id']) && $data['parent_category_id'] !== null && $data['parent_category_id'] !== '') {
        $stmt = $conn->prepare("
            INSERT INTO categories (category_name, description, store_id, parent_category_id)
            VALUES (?, ?, ?, ?)
        ");
        
        $description = isset($data['description']) ? $data['description'] : '';
        
        // PDO bind
$stmt->execute([$data['category_name'],
            $description,
            $data['store_id'],
            $data['parent_category_id']]);} else {
        $stmt = $conn->prepare("
            INSERT INTO categories (category_name, description, store_id)
            VALUES (?, ?, ?)
        ");
        
        $description = isset($data['description']) ? $data['description'] : '';
        
        // PDO bind
$stmt->execute([$data['category_name'],
            $description,
            $data['store_id']]);}
    
    if ($stmt->execute()) {
        $category_id = $conn->lastInsertId();
        
        
        sendResponse(true, 'Category added successfully', [
            'category_id' => $category_id
        ]);
    } else {
        $error = $stmt->error;
        
        
        sendResponse(false, 'Failed to add category: ' . $error);
    }
    
} catch (Exception $e) {
    if (isset($stmt)) {
        
    }
    if (isset($conn)) {
        
    }
    logError('Add category error: ' . $e->getMessage());
    sendResponse(false, 'Failed to add category: ' . $e->getMessage());
}
?>
