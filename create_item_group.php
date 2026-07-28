<?php
/**
 * Create Item Group Endpoint
 * Creates a new item group for a store
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

$conn = getDBConnection();
$data = getJSONInput();

validateRequired($data, ['store_id', 'group_name']);

try {
    // Check if group name already exists for this store
    $checkStmt = $conn->prepare("SELECT group_id FROM item_groups WHERE store_id = ? AND group_name = ?");
    // PDO bind
$checkStmt->execute([$data['store_id'], $data['group_name']]);$checkStmt->execute();
    if ($checkStmt->rowCount() > 0) {
        sendResponse(false, 'Group name already exists');
    }
    

    $description = $data['description'] ?? '';
    $color_code = $data['color_code'] ?? '#2196F3';
    $icon_name = $data['icon_name'] ?? '';
    $parent_group_id = isset($data['parent_group_id']) ? intval($data['parent_group_id']) : null;

    $stmt = $conn->prepare("INSERT INTO item_groups (store_id, group_name, description, color_code, icon_name, parent_group_id) VALUES (?, ?, ?, ?, ?, ?)");
    // PDO bind
$stmt->execute([$data['store_id'],
        $data['group_name'],
        $description,
        $color_code,
        $icon_name,
        $parent_group_id]);$stmt->execute();
    $group_id = $conn->lastInsertId();
    

    sendResponse(true, 'Item group created successfully', [
        'group_id' => $group_id
    ]);

} catch (Exception $e) {
    logError('Create item group error: ' . $e->getMessage());
    sendResponse(false, 'Failed to create item group');
}


?>
