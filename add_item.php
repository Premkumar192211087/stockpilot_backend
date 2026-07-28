<?php
/**
 * Add Product/Item with Image Upload Support
 * Accepts Base64 encoded images from mobile camera or gallery
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['product_name', 'sku', 'store_id']);

try {
    // Handle image upload if provided
    $imageUrl = '';
    if (!empty($data['image'])) {
        // Create upload directory if it doesn't exist
        $uploadDir = __DIR__ . '/uploads/products/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        // Decode Base64 image
        $imageData = base64_decode($data['image']);
        
        // Validate image data
        if ($imageData !== false && strlen($imageData) > 0) {
            // Generate unique filename
            $filename = 'product_' . uniqid() . '_' . time() . '.jpg';
            $filepath = $uploadDir . $filename;
            
            // Save image file
            if (file_put_contents($filepath, $imageData) !== false) {
                // Store relative URL for database
                $imageUrl = 'uploads/products/' . $filename;
            }
        }
    }
    
    $stmt = $conn->prepare("
        INSERT INTO products (
            store_id, product_name, sku, barcode, category,
            cost_price, price, quantity, image_url, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $status = $data['status'] ?? 'active';
    $category = $data['category'] ?? '';
    // PDO bind
$stmt->execute([$data['store_id'],
        $data['product_name'],
        $data['sku'],
        $data['barcode'] ?? null,
        $category,
        $data['cost_price'] ?? 0,
        $data['price'] ?? $data['selling_price'] ?? 0,
        $data['quantity'] ?? $data['current_stock'] ?? 0,
        $imageUrl,
        $status]);$stmt->execute();
    
    sendResponse(true, 'Product added successfully', [
        'product_id' => $conn->lastInsertId(),
        'image_url' => $imageUrl
    ]);
    
} catch (Exception $e) {
    // Delete uploaded image if database insert fails
    if (!empty($imageUrl) && file_exists(__DIR__ . '/uploads/products/' . basename($imageUrl))) {
        unlink(__DIR__ . '/uploads/products/' . basename($imageUrl));
    }
    
    logError('Add product error: ' . $e->getMessage());
    sendResponse(false, 'Failed to add product: ' . $e->getMessage());
}



?>
