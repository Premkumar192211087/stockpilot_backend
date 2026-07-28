<?php
/**
 * Get Customers
 */

require_once 'config.php';

$store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
if ($store_id <= 0) {
    sendResponse(false, 'Store ID is required');
}

$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$conn = getDBConnection();

try {
    // Customers are global; a store's customers are those who have transacted
    // with this store (derived from sales).
    $where = "c.customer_id IN (SELECT DISTINCT customer_id FROM sales WHERE store_id = ? AND customer_id IS NOT NULL)";
    $params = [$store_id];
    $types = "i";

    if ($customer_id > 0) {
        $where .= " AND c.customer_id = ?";
        $params[] = $customer_id;
        $types .= "i";
    }

    if (!empty($_GET['status']) && $_GET['status'] !== 'All') {
        $where .= " AND c.status = ?";
        $params[] = strtolower($_GET['status']);
        $types .= "s";
    }

    $searchParam = $_GET['search'] ?? $_GET['search_query'] ?? null;
    if (!empty($searchParam)) {
        $where .= " AND (c.customer_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
        $search = "%" . $searchParam . "%";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $types .= "sss";
    }

    $stmt = $conn->prepare("
        SELECT
            c.customer_id,
            c.customer_id AS customerId,
            c.customer_name,
            c.customer_name AS customerName,
            c.email,
            c.address,
            c.phone,
            c.status,
            $store_id AS store_id,
            $store_id AS storeId,
            c.registration_date,
            COALESCE(c.date_of_birth, '') AS dob,
            COALESCE(c.loyalty_points, 0) AS loyalty_points,
            COALESCE(c.loyalty_points, 0) AS loyaltyPoints,
            COALESCE(c.notes, '') AS notes,
            '' AS image_url,
            '' AS imageUrl
        FROM customers c
        WHERE $where
        ORDER BY c.customer_id DESC
    ");
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;

    $customers = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $customers[] = $row;
    }

    $payload = ['customers' => $customers];
    if ($customer_id > 0 && count($customers) > 0) {
        $payload['customer'] = $customers[0];
    }

    sendResponse(true, 'Customers retrieved successfully', $payload);
} catch (Exception $e) {
    logError('Get customers error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve customers');
}

if (isset($stmt)) {
    
}

?>
