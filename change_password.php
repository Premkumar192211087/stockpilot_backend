<?php
/**
 * Change Password
 * POST JSON: user_id, old_password, new_password
 */
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'POST method required');
}

$data = getJSONInput();
validateRequired($data, ['user_id', 'old_password', 'new_password']);

if (strlen($data['new_password']) < 6) {
    sendResponse(false, 'New password must be at least 6 characters');
}

$user_id     = (int)$data['user_id'];
$old_password = $data['old_password'];
$new_password = $data['new_password'];

$conn = getDBConnection();

try {
    $s = $conn->prepare("SELECT password_hash FROM user_login WHERE id = ? LIMIT 1");
    // PDO bind
$s->execute([$user_id]);$s->execute();
    $row = $s->fetch(PDO::FETCH_ASSOC);
    

    if (!$row) {
        sendResponse(false, 'User not found');
    }

    if (!password_verify($old_password, $row['password_hash'])) {
        sendResponse(false, 'Current password is incorrect');
    }

    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $u = $conn->prepare("UPDATE user_login SET password_hash = ? WHERE id = ?");
    // PDO bind
$u->execute([$new_hash, $user_id]);$u->execute();
    

    sendResponse(true, 'Password changed successfully');

} catch (Exception $e) {
    logError('change_password: ' . $e->getMessage());
    sendResponse(false, 'Failed to change password');
}


?>
