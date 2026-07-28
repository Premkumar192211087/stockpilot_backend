<?php
/**
 * Add Staff
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['store_id', 'full_name']);

$email = trim($data['email'] ?? '');
if ($email !== '' && !isValidEmail($email)) {
    sendResponse(false, 'Invalid email format');
}

function makeUsername($conn, $data) {
    $base = '';
    if (!empty($data['username'])) {
        $base = $data['username'];
    } elseif (!empty($data['email']) && strpos($data['email'], '@') !== false) {
        $base = substr($data['email'], 0, strpos($data['email'], '@'));
    } else {
        $base = $data['full_name'];
    }

    $base = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '.', trim($base)));
    $base = trim($base, '.');
    if ($base === '') {
        $base = 'staff';
    }

    $username = $base;
    $suffix = 1;
    $stmt = $conn->prepare("SELECT id FROM user_login WHERE username = ? LIMIT 1");
    while (true) {
        // PDO bind
$stmt->execute([$username]);$stmt->execute();
        if ($stmt->rowCount() === 0) {
            
            return $username;
        }
        $suffix++;
        $username = $base . $suffix;
    }
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
    $store_id = (int)$data['store_id'];
    $full_name = trim($data['full_name']);
    $phone = trim($data['phone'] ?? '');
    $role = trim($data['role'] ?? 'Staff');
    if ($role === '') $role = 'Staff';
    $address = trim($data['address'] ?? '');
    $password = $data['password'] ?? ($phone !== '' ? $phone : 'temp_pass');
    $username = makeUsername($conn, $data);
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $profileImage = !empty($data['profile_image'])
        ? saveStaffProfileImage($data['profile_image'], 'staff')
        : 'uploads/profiles/placeholder.png';

    $conn->beginTransaction();

    $userStmt = $conn->prepare("
        INSERT INTO user_login (username, Password, password_hash, store_id, role)
        VALUES (?, ?, ?, ?, ?)
    ");
    // PDO bind
$userStmt->execute([$username, $password, $passwordHash, $store_id, $role]);$userStmt->execute();
    $user_id = $conn->lastInsertId();
    

    $staffStmt = $conn->prepare("
        INSERT INTO staff_details (full_name, user_id, email, phone, role, store_id, address, profile_image)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // PDO bind
$staffStmt->execute([$full_name, $user_id, $email, $phone, $role, $store_id, $address, $profileImage]);$staffStmt->execute();
    $staff_id = $conn->lastInsertId();
    

    $conn->commit();

    sendResponse(true, 'Staff added successfully', [
        'staff_id' => $staff_id,
        'user_id' => $user_id,
        'username' => $username
    ]);
    
} catch (Exception $e) {
    if ($conn->errno === 0) {
        // no-op; keeps rollback guarded for drivers without active transaction status
    }
    $conn->rollBack();
    logError('Add staff error: ' . $e->getMessage());
    sendResponse(false, 'Failed to add staff');
}


?>