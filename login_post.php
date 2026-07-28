<?php
/**
 * Login — customer or vendor only.
 * role = 'customer' → CustomerMainActivity
 * role = 'vendor'   → VendorMainActivity
 */
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$data = getJSONInput();
validateRequired($data, ['username', 'password']);

$username = trim($data['username']);
$password = $data['password'];

$conn = getDBConnection();

try {
    $stmt = $conn->prepare("
        SELECT ul.id AS user_id, ul.username, ul.role,
               ul.customer_id, ul.supplier_id, ul.password_hash,
               ul.store_id AS vendor_store_id
        FROM user_login ul
        WHERE ul.username = ?
        LIMIT 1
    ");
    // PDO bind
$stmt->execute([$username]);$stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    

    if (!$user || !password_verify($password, $user['password_hash'])) {
        sendResponse(false, 'Invalid username or password');
    }

    unset($user['password_hash']);
    $user['token'] = bin2hex(random_bytes(32));
    $role = $user['role'];

    if ($role === 'customer' && !empty($user['customer_id'])) {
        // store_id comes from customers table, not user_login
        $s = $conn->prepare("
            SELECT customer_name AS full_name, email, phone, address,
                   loyalty_points, profile_image
            FROM customers WHERE customer_id = ? LIMIT 1
        ");
        // PDO bind
$s->execute([$user['customer_id']]);$s->execute();
        $row = $s->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $user['full_name']      = $row['full_name'];
            $user['email']          = $row['email'];
            $user['phone']          = $row['phone'];
            $user['address']        = $row['address'];
            $user['loyalty_points'] = (int)$row['loyalty_points'];
            $user['profile_image']  = $row['profile_image'];
            // store_id is NOT a customer property — customer picks store in the app
            $user['store_id']       = 0;
        }
        unset($user['vendor_store_id']);
    }

    if ($role === 'vendor' && !empty($user['supplier_id'])) {
        // store_id comes from user_login (which store they supply to)
        $user['store_id'] = (int)($user['vendor_store_id'] ?? 0);
        unset($user['vendor_store_id']);

        $s = $conn->prepare("
            SELECT supplier_name AS company_name, contact_person, email,
                   phone, address, payment_terms, profile_image
            FROM suppliers WHERE supplier_id = ? LIMIT 1
        ");
        // PDO bind
$s->execute([$user['supplier_id']]);$s->execute();
        $row = $s->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $user['full_name']     = $row['contact_person'] ?: $row['company_name'];
            $user['company_name']  = $row['company_name'];
            $user['email']         = $row['email'];
            $user['phone']         = $row['phone'];
            $user['address']       = $row['address'];
            $user['payment_terms'] = $row['payment_terms'];
            $user['profile_image'] = $row['profile_image'];
        }
    }

    sendResponse(true, 'Login successful', $user);

} catch (Exception $e) {
    logError('login: ' . $e->getMessage());
    sendResponse(false, 'Login failed');
}


?>
