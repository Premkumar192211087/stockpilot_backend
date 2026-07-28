<?php
/**
 * Update Bill - status, amounts, mark paid
 */

require_once 'config.php';
require_once 'notification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    sendResponse(false, 'PUT method required');
}

$bill_id = $_GET['bill_id'] ?? null;
$data = getJSONInput();
if (!$bill_id && isset($data['bill_id'])) $bill_id = $data['bill_id'];

if (!$bill_id) {
    sendResponse(false, 'Bill ID is required');
}

$conn = getDBConnection();

try {
    // Get current bill
    $stmt = $conn->prepare("SELECT * FROM bills WHERE bill_id = ?");
    // PDO bind
$stmt->execute([$bill_id]);$stmt->execute();
    $bill = $stmt->fetch(PDO::FETCH_ASSOC);
    

    if (!$bill) {
        sendResponse(false, 'Bill not found');
    }

    $updates = [];
    $params = [];
    $types = "";

    $allowed = ['status', 'due_date', 'subtotal', 'tax', 'discount', 'total', 'amount_paid', 'notes'];
    foreach ($allowed as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = $data[$field];
            $types .= is_numeric($data[$field]) && strpos((string)$data[$field], '.') !== false ? "d" : (is_int($data[$field]) ? "i" : "s");
        }
    }

    if (empty($updates)) {
        sendResponse(false, 'No fields to update');
    }

    $updates[] = "updated_at = NOW()";
    $params[] = $bill_id;
    $types .= "i";

    $sql = "UPDATE bills SET " . implode(", ", $updates) . " WHERE bill_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    

    // Auto-determine status from amount_paid if applicable
    if (isset($data['amount_paid'])) {
        $newPaid = (float)$data['amount_paid'];
        $total = (float)($data['total'] ?? $bill['total']);
        $autoStatus = 'pending';
        if ($newPaid >= $total) {
            $autoStatus = 'paid';
        } elseif ($newPaid > 0) {
            $autoStatus = 'partial';
        }
        $conn->query("UPDATE bills SET status = '$autoStatus' WHERE bill_id = $bill_id");
    }

    // Notify on status change
    $newStatus = $data['status'] ?? null;
    if ($newStatus && $newStatus !== $bill['status']) {
        notifyBillStatusUpdate($conn, $bill['store_id'], null, $bill_id, $bill['bill_number'], $newStatus);
    }

    sendResponse(true, 'Bill updated successfully');

} catch (Exception $e) {
    logError('Update bill error: ' . $e->getMessage());
    sendResponse(false, 'Failed to update bill');
}


?>
