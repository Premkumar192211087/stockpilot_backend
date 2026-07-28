<?php
/**
 * Update User Profile with Image Upload Support
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

// Accept user_id from either query string or JSON body
$user_id = $_GET['user_id'] ?? $data['user_id'] ?? null;

if (!$user_id) {
    sendResponse(false, 'User ID is required');
}

try {
    $conn->beginTransaction();
    
    // Handle profile image upload if provided
    $imageUrl = '';
    if (!empty($data['profile_image'])) {
        // Create upload directory if it doesn't exist
        $uploadDir = __DIR__ . '/uploads/profiles/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        // Get old image to delete if exists
        $oldImageStmt = $conn->prepare("SELECT profile_image FROM users WHERE user_id = ?");
        // PDO bind
$oldImageStmt->execute([$user_id]);$oldImageStmt->execute();
        // PDO result ready in $oldImageStmt
    $oldImageResult = $oldImageStmt;
        $oldImageRow = $oldImageResult->fetch(PDO::FETCH_ASSOC);
        $oldImageUrl = $oldImageRow['profile_image'] ?? '';
        
        
        // Delete old image file if exists
        if (!empty($oldImageUrl)) {
            $oldPath = __DIR__ . '/' . ltrim($oldImageUrl, '/');
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }
        
        // Decode Base64 image
        $imageData = base64_decode($data['profile_image']);
        
        // Validate image data
        if ($imageData !== false && strlen($imageData) > 0) {
            // Generate unique filename
            $filename = 'profile_' . $user_id . '_' . time() . '.jpg';
            $filepath = $uploadDir . $filename;
            
            // Save image file
            if (file_put_contents($filepath, $imageData) !== false) {
                // Store relative URL for database
                $imageUrl = 'uploads/profiles/' . $filename;
            }
        }
    }
    
    // Update users table if fields provided
    $updates = [];
    $types = "";
    $values = [];
    
    $allowed_fields = ['full_name', 'email', 'phone'];
    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $values[] = $data[$field];
            $types .= 's';
        }
    }
    
    // Add profile image to update if uploaded
    if (!empty($imageUrl)) {
        $updates[] = "profile_image = ?";
        $values[] = $imageUrl;
        $types .= 's';
    }
    
    // Update username if provided
    if (isset($data['username'])) {
        $updates[] = "username = ?";
        $values[] = $data['username'];
        $types .= 's';
    }
    
    if (!empty($updates)) {
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE user_id = ?";
        $types .= 'i';
        $values[] = $user_id;
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
    } else {
        sendResponse(false, 'No updates provided');
    }
    
    $conn->commit();
    sendResponse(true, 'Profile updated successfully', [
        'profile_image' => $imageUrl
    ]);
    
} catch (Exception $e) {
    $conn->rollBack();
    
    // Delete uploaded image if transaction fails
    if (!empty($imageUrl) && file_exists(__DIR__ . '/uploads/profiles/' . basename($imageUrl))) {
        unlink(__DIR__ . '/uploads/profiles/' . basename($imageUrl));
    }
    
    logError('Update profile error: ' . $e->getMessage());
    sendResponse(false, 'Failed to update profile: ' . $e->getMessage());
}


?>
