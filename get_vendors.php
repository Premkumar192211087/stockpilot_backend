<?php
/**
 * Get Vendors
 */

require_once 'config.php';

$store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
if ($store_id <= 0) {
    sendResponse(false, 'Store ID is required');
}

$supplier_id = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$conn = getDBConnection();

// Ensure score columns exist (created lazily by update_supplier_scores.php)
$conn->query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS performance_score DECIMAL(5,2) DEFAULT NULL");
$conn->query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS on_time_rate DECIMAL(5,2) DEFAULT NULL");
$conn->query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS avg_delay_days DECIMAL(6,2) DEFAULT NULL");
$conn->query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS total_pos_scored INT DEFAULT 0");
$conn->query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS score_updated_at TIMESTAMP NULL");

try {
    $where = "store_id = ?";
    $params = [$store_id];
    $types = "i";

    if ($supplier_id > 0) {
        $where .= " AND supplier_id = ?";
        $params[] = $supplier_id;
        $types .= "i";
    }

    if (!empty($_GET['status']) && $_GET['status'] !== 'All') {
        $where .= " AND status = ?";
        $params[] = strtolower($_GET['status']);
        $types .= "s";
    }

    $searchParam = $_GET['search'] ?? $_GET['search_query'] ?? null;
    if (!empty($searchParam)) {
        $where .= " AND (supplier_name LIKE ? OR contact_person LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $search = "%" . $searchParam . "%";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $types .= "ssss";
    }

    $stmt = $conn->prepare("
        SELECT
            supplier_id,
            supplier_id                        AS supplierId,
            supplier_name,
            supplier_name                      AS supplierName,
            contact_person,
            email,
            phone,
            address,
            payment_terms,
            payment_days,
            credit_limit,
            performance_score,
            on_time_rate,
            avg_delay_days,
            total_pos_scored,
            score_updated_at,
            status,
            store_id,
            store_id                           AS vendorStoreId,
            COALESCE(image_url, '')             AS image_url
        FROM suppliers
        WHERE $where
        ORDER BY supplier_id DESC
    ");
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;

    $vendors = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $vendors[] = $row;
    }

    $payload = ['vendors' => $vendors];
    if ($supplier_id > 0 && count($vendors) > 0) {
        $payload['vendor'] = $vendors[0];
        $payload['supplier'] = $vendors[0];
    }

    sendResponse(true, 'Vendors retrieved successfully', $payload);
} catch (Exception $e) {
    logError('Get vendors error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve vendors');
}

if (isset($stmt)) {
    
}

?>
