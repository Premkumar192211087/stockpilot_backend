<?php
require_once 'config.php';

// Get user_id from request
$user_id = $_GET['user_id'] ?? '';

// Validate input
if (empty($user_id)) {
    sendResponse(false, "User ID is required.");
}

$conn = getDBConnection();

// Query users table directly
$sql = "SELECT 
            user_id, 
            username, 
            full_name, 
            email, 
            phone, 
            role, 
            status, 
            store_id, 
            profile_image, 
            created_at 
        FROM users 
        WHERE user_id = ?";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    sendResponse(false, "Database preparation error: " . $conn->error);
}

// PDO bind
$stmt->execute([$user_id]);if ($stmt->execute()) {
    // PDO result ready in $stmt
    $result = $stmt;
    
    if ($result->num_rows > 0) {
        $row = $result->fetch(PDO::FETCH_ASSOC);
        
        // Prepare the data array, ensuring null values are converted to empty strings
        // This helps prevent issues on the Android client side
        $data = [
            "user_id" => $row['user_id'],
            "username" => $row['username'],
            "role" => $row['role'],
            "store_id" => $row['store_id'],
            "full_name" => $row['full_name'] ?? "",
            "email" => $row['email'] ?? "",
            "phone" => $row['phone'] ?? "",
            "profile_image" => $row['profile_image'] ?? "",
            "created_at" => $row['created_at'] ?? ""
        ];
        
        sendResponse(true, "Profile retrieved successfully", $data);
    } else {
        sendResponse(false, "User not found.");
    }
} else {
    sendResponse(false, "Database execution error: " . $stmt->error);
}



?>
