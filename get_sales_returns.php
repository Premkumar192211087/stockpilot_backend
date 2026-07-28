<?php
/**
 * Get Sales Returns
 * Queries the returns table (the actual DB table for sales returns).
 */

require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
$sale_id  = $_GET['sale_id']  ?? null;

if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$conn = getDBConnection();

try {
    $query = "
        SELECT
            r.return_id,
            r.return_number,
            r.sale_id,
            r.customer_id,
            r.store_id,
            r.return_date,
            r.return_amount,
            r.total_refund_amount,
            r.return_reason,
            r.processed_by,
            r.status,
            r.notes,
            s.invoice_number,
            COALESCE(c.customer_name, 'Walk-in Customer') AS customer_name
        FROM returns r
        LEFT JOIN sales s ON r.sale_id = s.sale_id
        LEFT JOIN customers c ON r.customer_id = c.customer_id
        WHERE r.store_id = ?
    ";

    $params = [(int)$store_id];
    $types  = "i";

    if ($sale_id) {
        $query   .= " AND r.sale_id = ?";
        $params[] = (int)$sale_id;
        $types   .= "i";
    }

    $query .= " ORDER BY r.return_date DESC";

    $stmt = $conn->prepare($query);
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;

    $items = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        // Normalize status to match app expectations
        $status_map = [
            'pending'   => 'pending',
            'processed' => 'approved',
            'cancelled' => 'rejected',
        ];
        $row['status'] = $status_map[$row['status']] ?? $row['status'];
        $items[] = $row;
    }

    sendResponse(true, 'Sales returns retrieved', ['items' => $items]);

} catch (Exception $e) {
    logError('Get sales returns error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve sales returns');
}


