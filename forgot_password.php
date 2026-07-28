<?php
/**
 * Forgot password endpoint.
 *
 * POST JSON:
 *   { "action": "request", "email": "user@example.com" }
 *   { "action": "reset", "email": "user@example.com", "code": "123456", "new_password": "secret123" }
 */

require_once 'config.php';
require_once 'smtp_mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();
$action = strtolower(trim((string)($data['action'] ?? '')));

function ensurePasswordResetTable($conn) {
    $sql = "
        CREATE TABLE IF NOT EXISTS password_reset_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            store_id INT NOT NULL,
            email VARCHAR(255) NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            verified_at DATETIME DEFAULT NULL,
            used_at DATETIME DEFAULT NULL,
            attempts INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_reset_email (email),
            INDEX idx_password_reset_user (user_id),
            INDEX idx_password_reset_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    if (!$conn->query($sql)) {
        throw new Exception('Failed to create password reset table: ' . $conn->error);
    }
}

function forgotPasswordTableExists($conn) {
    $result = $conn->query("SHOW TABLES LIKE 'password_reset_codes'");
    return $result && $result->num_rows > 0;
}

function forgotPasswordColumnExists($conn, $table, $column) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    // PDO bind
$stmt->execute([$table, $column]);$stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return (int)($row['count'] ?? 0) > 0;
}

function handleForgotPasswordRequest($conn, $data) {
    validateRequired($data, ['email']);

    $email = strtolower(trim((string)$data['email']));
    if (!isValidEmail($email)) {
        sendResponse(false, 'Invalid email format');
    }

    ensurePasswordResetTable($conn);

    $userStmt = $conn->prepare("
        SELECT ul.id AS user_id, ul.username, ul.store_id,
               COALESCE(NULLIF(sd.full_name, ''), ul.username) AS full_name,
               sd.email
        FROM user_login ul
        INNER JOIN staff_details sd ON sd.user_id = ul.id
        WHERE LOWER(sd.email) = ?
        LIMIT 1
    ");
    // PDO bind
$userStmt->execute([$email]);$userStmt->execute();
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    

    if (!$user) {
        sendResponse(false, 'No account found for this email');
    }

    $recentStmt = $conn->prepare("
        SELECT id
        FROM password_reset_codes
        WHERE email = ?
          AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
          AND used_at IS NULL
        LIMIT 1
    ");
    // PDO bind
$recentStmt->execute([$email]);$recentStmt->execute();
    $recentExists = $recentStmt->rowCount() > 0;
    

    if ($recentExists) {
        sendResponse(false, 'A reset code was sent recently. Please wait a minute before requesting another code.');
    }

    $code = (string)random_int(100000, 999999);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + 10 * 60);
    $userId = (int)$user['user_id'];
    $storeId = (int)$user['store_id'];

    $insertStmt = $conn->prepare("
        INSERT INTO password_reset_codes (user_id, store_id, email, code_hash, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    // PDO bind
$insertStmt->execute([$userId, $storeId, $email, $codeHash, $expiresAt]);$insertStmt->execute();
    

    $subject = 'Your StockPilot password reset code';
    $safeName = htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8');
    $html = "
        <div style=\"font-family:Arial,sans-serif;color:#1f2937;line-height:1.5\">
            <h2 style=\"margin:0 0 12px\">StockPilot password reset</h2>
            <p>Hello $safeName,</p>
            <p>Use this verification code to reset your password:</p>
            <div style=\"font-size:28px;font-weight:700;letter-spacing:6px;margin:18px 0;color:#0f766e\">$code</div>
            <p>This code expires in 10 minutes. If you did not request this, you can ignore this email.</p>
        </div>
    ";
    $text = "StockPilot password reset code: $code\nThis code expires in 10 minutes.";

    smtpSendMail($email, $user['full_name'], $subject, $html, $text);

    sendResponse(true, 'Reset code sent to your email', [
        'email' => $email,
        'expires_in_minutes' => 10
    ]);
}

function handleForgotPasswordReset($conn, $data) {
    validateRequired($data, ['email', 'code', 'new_password']);

    $email = strtolower(trim((string)$data['email']));
    $code = trim((string)$data['code']);
    $newPassword = (string)$data['new_password'];

    if (!isValidEmail($email)) {
        sendResponse(false, 'Invalid email format');
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        sendResponse(false, 'Enter the 6-digit verification code');
    }
    if (strlen($newPassword) < 6) {
        sendResponse(false, 'Password must be at least 6 characters');
    }
    if (!forgotPasswordTableExists($conn)) {
        sendResponse(false, 'Reset code is invalid or expired');
    }

    $codeStmt = $conn->prepare("
        SELECT prc.id, prc.user_id, prc.store_id, prc.code_hash, prc.attempts,
               ul.username
        FROM password_reset_codes prc
        INNER JOIN user_login ul ON ul.id = prc.user_id
        WHERE prc.email = ?
          AND prc.used_at IS NULL
          AND prc.expires_at >= NOW()
        ORDER BY prc.created_at DESC
        LIMIT 1
    ");
    // PDO bind
$codeStmt->execute([$email]);$codeStmt->execute();
    $reset = $codeStmt->fetch(PDO::FETCH_ASSOC);
    

    if (!$reset || (int)$reset['attempts'] >= 5) {
        sendResponse(false, 'Reset code is invalid or expired');
    }

    if (!password_verify($code, $reset['code_hash'])) {
        $attemptStmt = $conn->prepare("UPDATE password_reset_codes SET attempts = attempts + 1 WHERE id = ?");
        // PDO bind
$attemptStmt->execute([$reset['id']]);$attemptStmt->execute();
        
        sendResponse(false, 'Incorrect verification code');
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $conn->beginTransaction();

    $updateLogin = $conn->prepare("
        UPDATE user_login
        SET Password = ?, password_hash = ?
        WHERE id = ?
    ");
    // PDO bind
$updateLogin->execute([$newPassword, $passwordHash, $reset['user_id']]);$updateLogin->execute();
    

    if (forgotPasswordColumnExists($conn, 'users', 'password')) {
        $updateUsers = $conn->prepare("
            UPDATE users
            SET password = ?
            WHERE username = ?
        ");
        // PDO bind
$updateUsers->execute([$newPassword, $reset['username']]);$updateUsers->execute();
        
    }

    $usedStmt = $conn->prepare("
        UPDATE password_reset_codes
        SET verified_at = NOW(), used_at = NOW()
        WHERE id = ?
    ");
    // PDO bind
$usedStmt->execute([$reset['id']]);$usedStmt->execute();
    

    $conn->commit();
    sendResponse(true, 'Password reset successfully');
}

try {
    if ($action === 'request') {
        handleForgotPasswordRequest($conn, $data);
    } elseif ($action === 'reset') {
        handleForgotPasswordReset($conn, $data);
    } else {
        sendResponse(false, 'Invalid forgot password action');
    }
} catch (Exception $e) {
    try { $conn->rollBack(); } catch (Throwable $ignored) {}
    logError('Forgot password error: ' . $e->getMessage());
    sendResponse(false, 'Forgot password failed: ' . $e->getMessage());
}


?>
