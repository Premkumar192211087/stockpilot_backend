<?php
/**
 * Customer Signup
 * POST JSON: full_name, email, phone, password, address (opt), store_id (opt, default 1)
 */
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'POST method required');
}

$data = getJSONInput();
validateRequired($data, ['full_name', 'email', 'phone', 'password']);

if (!isValidEmail($data['email'])) {
    sendResponse(false, 'Invalid email format');
}
if (strlen($data['password']) < 6) {
    sendResponse(false, 'Password must be at least 6 characters');
}

$conn = getDBConnection();

try {
    $conn->beginTransaction();

    $store_id  = (int)($data['store_id'] ?? 1);
    $full_name = trim($data['full_name']);
    $email     = strtolower(trim($data['email']));
    $phone     = trim($data['phone']);
    $address   = trim($data['address'] ?? '');
    $pwd_hash  = password_hash($data['password'], PASSWORD_DEFAULT);

    // Email is globally unique for customers
    $dup = $conn->prepare("SELECT customer_id FROM customers WHERE email = ? LIMIT 1");
    // PDO bind
$dup->execute([$email]);$dup->execute();
    if ($dup->rowCount() > 0) {
        $conn->rollBack();
        sendResponse(false, 'An account with this email already exists');
    }
    

    // Build unique username from email prefix
    $username = strtolower(preg_replace('/[^a-zA-Z0-9._]/', '', explode('@', $email)[0])) . '_' . $store_id;
    $base = $username;
    $attempt = 0;
    do {
        $chk = $conn->prepare("SELECT id FROM user_login WHERE username = ? LIMIT 1");
        // PDO bind
$chk->execute([$username]);$chk->execute();
        $exists = $chk->rowCount() > 0;
        
        if ($exists) $username = $base . (++$attempt);
    } while ($exists && $attempt < 20);

    // Create customer record
    $cs = $conn->prepare("INSERT INTO customers (customer_name, email, phone, address, status) VALUES (?, ?, ?, ?, 'active')");
    // PDO bind
$cs->execute([$full_name, $email, $phone, $address]);$cs->execute();
    $customer_id = $conn->lastInsertId();
    

    // store_id is NOT stored in user_login for customers — it lives in the customers table
    $ls = $conn->prepare("INSERT INTO user_login (username, password_hash, role, customer_id) VALUES (?, ?, 'customer', ?)");
    // PDO bind
$ls->execute([$username, $pwd_hash, $customer_id]);$ls->execute();
    $user_id = $conn->lastInsertId();
    

    $conn->commit();

    sendResponse(true, 'Account created successfully', [
        'user_id'     => $user_id,
        'customer_id' => $customer_id,
        'username'    => $username,
        'role'        => 'customer',
        'store_id'    => $store_id,
        'full_name'   => $full_name,
        'email'       => $email,
        'loyalty_points' => 0,
        'token'       => bin2hex(random_bytes(32)),
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    logError('customer_signup: ' . $e->getMessage());
    sendResponse(false, 'Failed to create account: ' . $e->getMessage());
}


?>
