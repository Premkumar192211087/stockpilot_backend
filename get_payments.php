<?php
/**
 * Get Payments
 */

require_once 'config.php';

function normalizePaymentTypeValue($value) {
    $value = strtolower(trim((string)$value));
    if ($value === '' || $value === 'all') return '';
    if (strpos($value, 'made') !== false || strpos($value, 'paid') !== false || strpos($value, 'out') !== false) return 'made';
    return 'received';
}

$store_id = $_GET['store_id'] ?? null;
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

$payment_id = $_GET['payment_id'] ?? null;
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$type = normalizePaymentTypeValue($_GET['type'] ?? '');

$conn = getDBConnection();

try {
    $query = "
        SELECT 
            p.payment_id,
            p.store_id,
            p.invoice_id,
            p.po_id,
            p.payment_type,
            p.amount,
            p.payment_method,
            p.payment_date,
            p.reference_number,
            p.customer_id,
            p.supplier_id,
            p.notes,
            p.status,
            p.created_at,
            COALESCE(c.customer_name, '') as customer_name,
            COALESCE(s.supplier_name, '') as supplier_name,
            COALESCE(i.invoice_number, '') as invoice_number,
            COALESCE(po.po_number, '') as po_number
        FROM payments p
        LEFT JOIN customers c ON p.customer_id = c.customer_id
        LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id AND p.store_id = s.store_id
        LEFT JOIN invoices i ON p.invoice_id = i.invoice_id AND p.store_id = i.store_id
        LEFT JOIN purchase_orders po ON p.po_id = po.po_id AND p.store_id = po.store_id
        WHERE p.store_id = ?
    ";
    
    $params = [(int)$store_id];
    $types = "i";

    if ($payment_id) {
        $query .= " AND p.payment_id = ?";
        $params[] = (int)$payment_id;
        $types .= "i";
    }
    
    if ($type !== '') {
        $query .= " AND p.payment_type = ?";
        $params[] = $type;
        $types .= "s";
    }
    
    if (!empty($status) && $status !== 'all') {
        $query .= " AND p.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    if (!empty($search)) {
        $query .= " AND (p.reference_number LIKE ? OR p.notes LIKE ? OR c.customer_name LIKE ? OR s.supplier_name LIKE ? OR i.invoice_number LIKE ? OR po.po_number LIKE ?)";
        $searchParam = "%$search%";
        for ($i = 0; $i < 6; $i++) {
            $params[] = $searchParam;
            $types .= "s";
        }
    }
    
    $query .= " ORDER BY p.payment_date DESC, p.created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    // PDO result ready in $stmt
    $result = $stmt;
    
    $payments = [];
    $total_received = 0;
    $total_made = 0;
    
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $payments[] = $row;
        if ($row['payment_type'] === 'received') {
            $total_received += floatval($row['amount']);
        } else {
            $total_made += floatval($row['amount']);
        }
    }
    
    $summary = [
        'total_count' => count($payments),
        'total_received' => round($total_received, 2),
        'total_made' => round($total_made, 2)
    ];

    $payload = [
        'payments' => $payments,
        'summary' => $summary
    ];
    if ($payment_id && count($payments) > 0) {
        $payload['payment'] = $payments[0];
    }
    
    sendResponse(true, 'Payments retrieved successfully', $payload);
    
} catch (Exception $e) {
    logError('Get payments error: ' . $e->getMessage());
    sendResponse(false, 'Failed to retrieve payments');
}

if (isset($stmt)) {
    
}

?>