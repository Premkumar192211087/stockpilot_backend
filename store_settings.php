<?php
/**
 * Store Settings API
 * GET  ?store_id=X           — all settings as key-value map
 * GET  ?store_id=X&key=K     — single value
 * POST JSON: store_id, setting_key, setting_value, setting_type — upsert
 */
require_once 'config.php';

$method   = $_SERVER['REQUEST_METHOD'];
$conn     = getDBConnection();

try {
    if ($method === 'GET') {
        $store_id = (int)($_GET['store_id'] ?? 0);
        $key      = sanitizeString($_GET['key'] ?? '');
        if ($store_id <= 0) sendResponse(false, 'store_id required');

        if (!empty($key)) {
            $s = $conn->prepare("SELECT setting_value, setting_type FROM store_settings WHERE store_id = ? AND setting_key = ?");
            // PDO bind
$s->execute([$store_id, $key]);$s->execute();
            $row = $s->fetch(PDO::FETCH_ASSOC);
            
            sendResponse(true, 'Setting loaded', $row ?: ['setting_value' => null]);
        } else {
            $s = $conn->prepare("SELECT setting_key, setting_value, setting_type FROM store_settings WHERE store_id = ?");
            // PDO bind
$s->execute([$store_id]);$s->execute();
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            

            $map = [];
            foreach ($rows as $r) {
                $map[$r['setting_key']] = $r['setting_value'];
            }
            sendResponse(true, 'Settings loaded', ['settings' => $map, 'raw' => $rows]);
        }

    } elseif ($method === 'POST' || $method === 'PUT') {
        $data = getJSONInput();
        validateRequired($data, ['store_id', 'setting_key', 'setting_value']);

        $store_id = (int)$data['store_id'];
        $key      = sanitizeString($data['setting_key']);
        $val      = $data['setting_value'];
        $type     = $data['setting_type'] ?? 'string';

        $s = $conn->prepare("
            INSERT INTO store_settings (store_id, setting_key, setting_value, setting_type)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (store_id, setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, setting_type = EXCLUDED.setting_type
        ");
        // PDO bind
$s->execute([$store_id, $key, $val, $type]);$s->execute();
        

        sendResponse(true, 'Setting saved');
    } else {
        sendResponse(false, 'Method not allowed');
    }

} catch (Exception $e) {
    logError('store_settings: ' . $e->getMessage());
    sendResponse(false, 'Error: ' . $e->getMessage());
}


?>
