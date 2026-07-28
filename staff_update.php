<?php
/**
 * Update Staff
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

$staff_id = $_GET['staff_id'] ?? $data['staff_id'] ?? null;
$store_id = $_GET['store_id'] ?? $data['store_id'] ?? null;
if (!$staff_id) {
    sendResponse(false, 'Staff ID is required');
}
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$email = trim($data['email'] ?? '');
if ($email !== '' && !isValidEmail($email)) {
    sendResponse(false, 'Invalid email format');
}

function saveStaffProfileImage($base64, $prefix) {
    if (!$base64 || strpos($base64, 'uploads/') === 0 || strpos($base64, 'http') === 0) {
        return $base64;
    }

    if (strpos($base64, ',') !== false) {
        $base64 = substr($base64, strpos($base64, ',') + 1);
    }

    $imageData = base64_decode($base64, true);
    if ($imageData === false) {
        throw new Exception('Invalid profile image');
    }

    $uploadDir = __DIR__ . '/uploads/profiles/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $fileName = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $path = $uploadDir . $fileName;
    if (file_put_contents($path, $imageData) === false) {
        throw new Exception('Failed to save profile image');
    }

    return 'uploads/profiles/' . $fileName;
}

try {
    $lookupStmt = $conn->prepare("SELECT user_id FROM staff_details WHERE staff_id = ? AND store_id = ? LIMIT 1");
    // PDO bind
$lookupStmt->execute([$staff_id, $store_id]);$lookupStmt->execute();
    // PDO result ready in $lookupStmt
    $lookupResult = $lookupStmt;
    if ($lookupResult->num_rows === 0) {
        sendResponse(false, 'Staff not found');
    }
    $staff = $lookupResult->fetch(PDO::FETCH_ASSOC);
    $user_id = (int)$staff['user_id'];
    

    $updates = [];
    $types = '';
    $values = [];
    $allowedFields = ['full_name', 'email', 'phone', 'role', 'address'];

    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $data)) {
            $updates[] = "$field = ?";
            $values[] = trim((string)$data[$field]);
            $types .= 's';
        }
    }

    if (!empty($data['profile_image'])) {
        $updates[] = "profile_image = ?";
        $values[] = saveStaffProfileImage($data['profile_image'], 'staff_' . $staff_id);
        $types .= 's';
    }

    if (empty($updates)) {
        sendResponse(false, 'No fields to update');
    }

    $conn->beginTransaction();

    $sql = "UPDATE staff_details SET " . implode(', ', $updates) . " WHERE staff_id = ? AND store_id = ?";
    $types .= 'ii';
    $values[] = $staff_id;
    $values[] = $store_id;
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    

    if (array_key_exists('role', $data)) {
        $role = trim((string)$data['role']);
        $userStmt = $conn->prepare("UPDATE user_login SET role = ? WHERE id = ? AND store_id = ?");
        // PDO bind
$userStmt->execute([$role, $user_id, $store_id]);$userStmt->execute();
        
    }

    $conn->commit();
    sendResponse(true, 'Staff updated successfully');
    
} catch (Exception $e) {
    $conn->rollBack();
    logError('Update staff error: ' . $e->getMessage());
    sendResponse(false, 'Failed to update staff');
}


?>