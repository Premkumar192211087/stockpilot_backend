<?php
/**
 * Get Inventory Adjustments
 * Since inventory_adjustments table doesn't exist, return empty array
 */

require_once 'config.php';

$store_id = $_GET['store_id'] ?? null;
if (!$store_id) {
    sendResponse(false, 'Store ID is required');
}

// The inventory_adjustments table does not exist in the current schema.
// Return an empty array with success response.
sendResponse(true, 'Adjustments retrieved', []);
