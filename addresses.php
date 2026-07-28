<?php
/**
 * Addresses API — CRUD customer shipping/billing addresses
 * GET/POST ?action=get|add|update|delete&customer_id=X
 */
require_once 'config.php';

// For JSON POST requests, $_REQUEST/$_GET won't contain the body fields.
// Parse the raw JSON body first so we can fall back to it.
$_JSON_INPUT = json_decode(file_get_contents('php://input'), true) ?? [];

$action      = $_REQUEST['action']      ?? $_GET['action']      ?? $_JSON_INPUT['action']      ?? '';
$customer_id = (int)($_REQUEST['customer_id'] ?? $_GET['customer_id'] ?? $_JSON_INPUT['customer_id'] ?? 0);

if ($customer_id <= 0) sendResponse(false, 'customer_id required');

$conn = getDBConnection();

try {
    switch ($action) {
        case 'get':
            $s = $conn->prepare("SELECT * FROM addresses WHERE customer_id = ? ORDER BY is_default DESC, address_id ASC");
            // PDO bind
$s->execute([$customer_id]);$s->execute();
            $addresses = $s->fetchAll(PDO::FETCH_ASSOC);
            
            sendResponse(true, 'Addresses loaded', ['addresses' => $addresses]);
            break;

        case 'add':
            $data = getJSONInput();
            validateRequired($data, ['address_line1', 'city', 'state', 'postal_code']);

            $label   = sanitizeString($data['label'] ?? 'Home');
            $name    = sanitizeString($data['full_name'] ?? '');
            $phone   = sanitizeString($data['phone'] ?? '');
            $line1   = sanitizeString($data['address_line1']);
            $line2   = sanitizeString($data['address_line2'] ?? '');
            $city    = sanitizeString($data['city']);
            $state   = sanitizeString($data['state']);
            $postal  = sanitizeString($data['postal_code']);
            $country = sanitizeString($data['country'] ?? 'India');
            $default = (int)($data['is_default'] ?? 0);
            $type    = $data['address_type'] ?? 'both';

            if ($default) {
                $conn->query("UPDATE addresses SET is_default = 0 WHERE customer_id = $customer_id");
            }

            $s = $conn->prepare("INSERT INTO addresses (customer_id, label, full_name, phone, address_line1, address_line2, city, state, postal_code, country, is_default, address_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            // PDO bind
$s->execute([$customer_id, $label, $name, $phone, $line1, $line2, $city, $state, $postal, $country, $default, $type]);$s->execute();
            $new_id = $conn->lastInsertId();
            

            sendResponse(true, 'Address added', ['address_id' => $new_id]);
            break;

        case 'update':
            $data = getJSONInput();
            $address_id = (int)($data['address_id'] ?? 0);
            if ($address_id <= 0) sendResponse(false, 'address_id required');

            $fields = [];
            $types = '';
            $values = [];

            foreach (['label','full_name','phone','address_line1','address_line2','city','state','postal_code','country','address_type'] as $f) {
                if (isset($data[$f])) {
                    $fields[] = "$f = ?";
                    $types .= 's';
                    $values[] = sanitizeString($data[$f]);
                }
            }

            if (isset($data['is_default'])) {
                $isDefault = (int)$data['is_default'];
                if ($isDefault) {
                    $conn->query("UPDATE addresses SET is_default = 0 WHERE customer_id = $customer_id");
                }
                $fields[] = "is_default = ?";
                $types .= 'i';
                $values[] = $isDefault;
            }

            if (empty($fields)) sendResponse(false, 'No fields to update');

            $types .= 'ii';
            $values[] = $address_id;
            $values[] = $customer_id;

            $sql = "UPDATE addresses SET " . implode(', ', $fields) . " WHERE address_id = ? AND customer_id = ?";
            $s = $conn->prepare($sql);
            $s->execute();
            

            sendResponse(true, 'Address updated');
            break;

        case 'delete':
            $address_id = (int)($_REQUEST['address_id'] ?? 0);
            if ($address_id <= 0) {
                $data = getJSONInput();
                $address_id = (int)($data['address_id'] ?? 0);
            }
            if ($address_id <= 0) sendResponse(false, 'address_id required');

            // Don't delete if it's the only one
            $c = $conn->prepare("SELECT COUNT(*) n FROM addresses WHERE customer_id = ?");
            // PDO bind
$c->execute([$customer_id]);$c->execute();
            $count = (int)$c->fetch(PDO::FETCH_ASSOC)['n'];
            

            if ($count <= 1) sendResponse(false, 'Cannot delete the only address');

            $d = $conn->prepare("DELETE FROM addresses WHERE address_id = ? AND customer_id = ?");
            // PDO bind
$d->execute([$address_id, $customer_id]);$d->execute();
            

            sendResponse(true, 'Address deleted');
            break;

        default:
            sendResponse(false, 'Invalid action. Use: get, add, update, delete');
    }
} catch (Exception $e) {
    logError('addresses error: ' . $e->getMessage());
    sendResponse(false, 'Address operation failed');
}

?>
