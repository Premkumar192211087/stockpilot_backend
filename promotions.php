<?php
/**
 * Promotions Admin API — CRUD
 * GET  ?store_id=X              — list all promotions
 * POST JSON                     — create promotion
 * PUT  JSON (promo_id required) — update promotion
 * DELETE ?promo_id=X            — deactivate promotion
 */
require_once 'config.php';

$method   = $_SERVER['REQUEST_METHOD'];
$store_id = (int)($_GET['store_id'] ?? 0);
$conn     = getDBConnection();

try {
    if ($method === 'GET') {
        if ($store_id <= 0) sendResponse(false, 'store_id required');

        $s = $conn->prepare("
            SELECT p.*,
                   (SELECT COUNT(*) FROM coupon_usage cu WHERE cu.promo_id = p.promo_id) AS total_used
            FROM promotions p
            WHERE p.store_id = ?
            ORDER BY p.created_at DESC
        ");
        // PDO bind
$s->execute([$store_id]);$s->execute();
        $promos = $s->fetchAll(PDO::FETCH_ASSOC);
        

        foreach ($promos as &$p) {
            $p['promo_id']         = (int)$p['promo_id'];
            $p['store_id']         = (int)$p['store_id'];
            $p['discount_value']   = (float)$p['discount_value'];
            $p['min_order_amount'] = (float)($p['min_order_amount'] ?? 0);
            $p['is_active']        = (int)$p['is_active'];
            $p['usage_count']      = (int)$p['usage_count'];
            $p['total_used']       = (int)$p['total_used'];
        }
        unset($p);

        sendResponse(true, 'Promotions loaded', [
            'promotions' => $promos,
            'count'      => count($promos),
        ]);

    } elseif ($method === 'POST') {
        $data = getJSONInput();
        validateRequired($data, ['store_id', 'promo_code', 'promo_name', 'discount_type', 'discount_value', 'start_date', 'end_date']);

        $sid        = (int)$data['store_id'];
        $code       = strtoupper(sanitizeString($data['promo_code']));
        $name       = sanitizeString($data['promo_name']);
        $desc       = sanitizeString($data['description'] ?? '');
        $dtype      = $data['discount_type'];
        $dvalue     = (float)$data['discount_value'];
        $min_order  = (float)($data['min_order_amount'] ?? 0);
        $max_disc   = isset($data['max_discount_amount']) ? (float)$data['max_discount_amount'] : null;
        $start      = $data['start_date'];
        $end        = $data['end_date'];
        $limit      = isset($data['usage_limit']) ? (int)$data['usage_limit'] : null;
        $per_cust   = (int)($data['per_customer_limit'] ?? 1);
        $app_to     = $data['applicable_to'] ?? 'all';
        $app_ids    = $data['applicable_ids'] ?? null;
        $is_active  = (int)($data['is_active'] ?? 1);

        $s = $conn->prepare("
            INSERT INTO promotions (store_id, promo_code, promo_name, description, discount_type,
                discount_value, min_order_amount, max_discount_amount, start_date, end_date,
                usage_limit, per_customer_limit, applicable_to, applicable_ids, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        // PDO bind
$s->execute([$sid, $code, $name, $desc, $dtype,
            $dvalue, $min_order, $max_disc, $start, $end,
            $limit, $per_cust, $app_to, $app_ids, $is_active]);$s->execute();
        $new_id = $conn->lastInsertId();
        

        sendResponse(true, 'Promotion created', ['promo_id' => $new_id]);

    } elseif ($method === 'PUT') {
        $data = getJSONInput();
        validateRequired($data, ['promo_id', 'store_id']);

        $promo_id = (int)$data['promo_id'];
        $fields   = [];
        $types    = '';
        $values   = [];

        $map = [
            'promo_code'        => 's',
            'promo_name'        => 's',
            'description'       => 's',
            'discount_type'     => 's',
            'discount_value'    => 'd',
            'min_order_amount'  => 'd',
            'max_discount_amount' => 'd',
            'start_date'        => 's',
            'end_date'          => 's',
            'usage_limit'       => 'i',
            'per_customer_limit' => 'i',
            'applicable_to'     => 's',
            'applicable_ids'    => 's',
            'is_active'         => 'i',
        ];

        foreach ($map as $key => $type) {
            if (isset($data[$key])) {
                $fields[] = "`$key` = ?";
                $types   .= $type;
                $values[] = $data[$key];
            }
        }

        if (empty($fields)) sendResponse(false, 'No fields to update');

        $types   .= 'ii';
        $values[] = $promo_id;
        $values[] = (int)$data['store_id'];

        $sql = "UPDATE promotions SET " . implode(', ', $fields) . " WHERE promo_id = ? AND store_id = ?";
        $s   = $conn->prepare($sql);
        $s->execute();
        

        sendResponse(true, 'Promotion updated');

    } elseif ($method === 'DELETE') {
        $promo_id = (int)($_GET['promo_id'] ?? 0);
        $sid      = (int)($_GET['store_id'] ?? 0);
        if ($promo_id <= 0 || $sid <= 0) sendResponse(false, 'promo_id and store_id required');

        $s = $conn->prepare("UPDATE promotions SET is_active = 0 WHERE promo_id = ? AND store_id = ?");
        // PDO bind
$s->execute([$promo_id, $sid]);$s->execute();
        

        sendResponse(true, 'Promotion deactivated');
    } else {
        sendResponse(false, 'Method not allowed');
    }

} catch (Exception $e) {
    logError('promotions: ' . $e->getMessage());
    sendResponse(false, 'Error: ' . $e->getMessage());
}


?>
