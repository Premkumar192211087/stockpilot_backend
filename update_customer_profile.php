<?php
/**
 * Update Customer Profile
 * POST JSON: customer_id, user_id, full_name (opt), phone (opt), address (opt), profile_image base64 (opt)
 */
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'POST method required');
}

$data = getJSONInput();
validateRequired($data, ['customer_id', 'user_id']);

$customer_id = (int)$data['customer_id'];
$user_id     = (int)$data['user_id'];

$conn = getDBConnection();

try {
    $conn->beginTransaction();

    $image_url = null;
    if (!empty($data['profile_image'])) {
        $upload_dir = __DIR__ . '/uploads/profiles/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $image_data = base64_decode($data['profile_image']);
        if ($image_data !== false && strlen($image_data) > 100) {
            // Delete old image
            $old = $conn->prepare("SELECT profile_image FROM customers WHERE customer_id = ?");
            // PDO bind
$old->execute([$customer_id]);$old->execute();
            $old_row = $old->fetch(PDO::FETCH_ASSOC);
            
            if (!empty($old_row['profile_image'])) {
                $old_path = __DIR__ . '/' . ltrim($old_row['profile_image'], '/');
                if (file_exists($old_path)) unlink($old_path);
            }

            $filename  = 'cust_' . $customer_id . '_' . time() . '.jpg';
            if (file_put_contents($upload_dir . $filename, $image_data) !== false) {
                $image_url = 'uploads/profiles/' . $filename;
            }
        }
    }

    // Build update for customers table
    $fields = [];
    $types  = '';
    $values = [];

    if (isset($data['full_name'])) {
        $fields[]  = 'customer_name = ?';
        $types    .= 's';
        $values[]  = sanitizeString($data['full_name']);
    }
    if (isset($data['phone'])) {
        $fields[]  = 'phone = ?';
        $types    .= 's';
        $values[]  = sanitizeString($data['phone']);
    }
    if (isset($data['address'])) {
        $fields[]  = 'address = ?';
        $types    .= 's';
        $values[]  = sanitizeString($data['address']);
    }
    if ($image_url !== null) {
        $fields[]  = 'profile_image = ?';
        $types    .= 's';
        $values[]  = $image_url;
    }

    if (empty($fields)) {
        $conn->rollBack();
        sendResponse(false, 'Nothing to update');
    }

    $types   .= 'i';
    $values[] = $customer_id;
    $sql      = 'UPDATE customers SET ' . implode(', ', $fields) . ' WHERE customer_id = ?';
    $s = $conn->prepare($sql);
    $s->execute();
    

    $conn->commit();

    // Return updated profile
    $p = $conn->prepare("SELECT customer_name AS full_name, email, phone, address, loyalty_points, profile_image FROM customers WHERE customer_id = ?");
    // PDO bind
$p->execute([$customer_id]);$p->execute();
    $profile = $p->fetch(PDO::FETCH_ASSOC);
    

    sendResponse(true, 'Profile updated', $profile);

} catch (Exception $e) {
    $conn->rollBack();
    logError('update_customer_profile: ' . $e->getMessage());
    sendResponse(false, 'Update failed: ' . $e->getMessage());
}


?>
