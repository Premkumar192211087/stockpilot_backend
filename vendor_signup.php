<?php
/**
 * Vendor Self-Registration
 * Creates a suppliers row + user_login row with role='vendor'.
 * Does NOT create a new store — vendor joins an existing store as a supplier.
 *
 * POST JSON: company_name, contact_person (opt), email, phone,
 *            address (opt), payment_terms (opt), username, password, store_id (opt, default 1)
 */
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'POST method required');
}

$data = getJSONInput();
validateRequired($data, ['company_name', 'email', 'phone', 'username', 'password']);

if (!isValidEmail($data['email'])) {
    sendResponse(false, 'Invalid email format');
}
if (strlen($data['password']) < 6) {
    sendResponse(false, 'Password must be at least 6 characters');
}

$conn = getDBConnection();

try {
    $conn->beginTransaction();

    $store_id      = (int)($data['store_id'] ?? 1);
    $company_name  = trim($data['company_name']);
    $contact       = trim($data['contact_person'] ?? '');
    $email         = strtolower(trim($data['email']));
    $phone         = trim($data['phone']);
    $address       = trim($data['address'] ?? '');
    $payment_terms = trim($data['payment_terms'] ?? 'Net 30');
    $username      = trim($data['username']);
    $pwd_hash      = password_hash($data['password'], PASSWORD_DEFAULT);

    // Username uniqueness
    $dup = $conn->prepare("SELECT id FROM user_login WHERE username = ? LIMIT 1");
    // PDO bind
$dup->execute([$username]);$dup->execute();
    if ($dup->rowCount() > 0) {
        $conn->rollBack();
        sendResponse(false, 'Username already taken. Please choose another.');
    }
    

    // Email uniqueness for vendors
    $dup2 = $conn->prepare("SELECT supplier_id FROM suppliers WHERE email = ? AND store_id = ? LIMIT 1");
    // PDO bind
$dup2->execute([$email, $store_id]);$dup2->execute();
    if ($dup2->rowCount() > 0) {
        $conn->rollBack();
        sendResponse(false, 'A vendor account with this email already exists');
    }
    

    // Verify store exists
    $sv = $conn->prepare("SELECT store_id FROM stores WHERE store_id = ? AND status = 'active' LIMIT 1");
    // PDO bind
$sv->execute([$store_id]);$sv->execute();
    if ($sv->rowCount() === 0) {
        $conn->rollBack();
        sendResponse(false, 'Invalid store');
    }
    

    // Create supplier record
    $ss = $conn->prepare("
        INSERT INTO suppliers (store_id, supplier_name, contact_person, email, phone, address, payment_terms, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
    ");
    // PDO bind
$ss->execute([$store_id, $company_name, $contact, $email, $phone, $address, $payment_terms]);$ss->execute();
    $supplier_id = $conn->lastInsertId();
    

    // Create login record
    $ls = $conn->prepare("INSERT INTO user_login (username, password_hash, store_id, role, supplier_id) VALUES (?, ?, ?, 'vendor', ?)");
    // PDO bind
$ls->execute([$username, $pwd_hash, $store_id, $supplier_id]);$ls->execute();
    $user_id = $conn->lastInsertId();
    

    $conn->commit();

    $display_name = $contact ?: $company_name;
    sendResponse(true, 'Vendor account created successfully', [
        'user_id'      => $user_id,
        'supplier_id'  => $supplier_id,
        'store_id'     => $store_id,
        'username'     => $username,
        'role'         => 'vendor',
        'full_name'    => $display_name,
        'company_name' => $company_name,
        'email'        => $email,
        'token'        => bin2hex(random_bytes(32)),
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    logError('vendor_signup: ' . $e->getMessage());
    sendResponse(false, 'Failed to create vendor account: ' . $e->getMessage());
}


?>
