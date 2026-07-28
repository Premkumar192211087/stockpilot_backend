<?php
/**
 * Get Staff
 */

require_once 'config.php';

$store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
$staff_id = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;
if ($store_id <= 0) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    $where = "sd.store_id = ?";
    $params = [$store_id];
    $types = "i";

    if ($staff_id > 0) {
        $where .= " AND sd.staff_id = ?";
        $params[] = $staff_id;
        $types .= "i";
    }

    $stmt = $conn->prepare("
        SELECT
            sd.staff_id,
            sd.full_name,
            sd.user_id,
            ul.username,
            sd.email,
            sd.phone,
            sd.role,
            sd.store_id,
            sd.address,
            sd.profile_image
        FROM staff_details sd
        LEFT JOIN user_login ul ON ul.id = sd.user_id
        WHERE $where
        ORDER BY sd.staff_id DESC
    ");
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;

    $staff = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $staff[] = $row;
    }

    $payload = ['staff' => $staff];
    if ($staff_id > 0 && count($staff) > 0) {
        $payload['staff_member'] = $staff[0];
    }

    sendResponse(true, 'Staff retrieved successfully', $payload);
} catch (Exception $e) {
    logError('Get staff error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve staff');
}

if (isset($stmt)) {
    
}

?>
