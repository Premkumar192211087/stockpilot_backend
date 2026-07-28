<?php
/**
 * Update Item Group Endpoint
 * Updates an existing item group
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['group_id', 'store_id', 'group_name']);

try {
    // Verify group exists
    $checkStmt = $conn->prepare("SELECT group_id FROM item_groups WHERE group_id = ? AND store_id = ?");
    // PDO bind
$checkStmt->execute([$data['group_id'], $data['store_id']]);$checkStmt->execute();
    if ($checkStmt->rowCount() === 0) {
        sendResponse(false, 'Item group not found');
    }
    

    // Check for duplicate name (excluding current group)
    $checkStmt = $conn->prepare("SELECT group_id FROM item_groups WHERE store_id = ? AND group_name = ? AND group_id != ?");
    // PDO bind
$checkStmt->execute([$data['store_id'], $data['group_name'], $data['group_id']]);$checkStmt->execute();
    if ($checkStmt->rowCount() > 0) {
        sendResponse(false, 'Group name already exists');
    }
    

    $description = $data['description'] ?? '';
    $color_code = $data['color_code'] ?? '#2196F3';
    $icon_name = $data['icon_name'] ?? '';

    $stmt = $conn->prepare("UPDATE item_groups SET group_name = ?, description = ?, color_code = ?, icon_name = ? WHERE group_id = ? AND store_id = ?");
    // PDO bind
$stmt->execute([$data['group_name'],
        $description,
        $color_code,
        $icon_name,
        $data['group_id'],
        $data['store_id']]);$stmt->execute();

    if ($stmt->affected_rows >= 0) {
        sendResponse(true, 'Item group updated successfully');
    } else {
        sendResponse(false, 'Failed to update item group');
    }
    

} catch (Exception $e) {
    logError('Update item group error: ' . $e->getMessage());
    sendResponse(false, 'Failed to update item group');
}


?>
