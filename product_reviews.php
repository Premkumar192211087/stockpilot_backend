<?php
/**
 * Product Reviews API
 * GET  ?product_id=X&store_id=X&page=1
 * POST JSON: submit review
 * PUT  JSON: admin approve/reject (review_id, status)
 */
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn   = getDBConnection();

try {
    if ($method === 'GET') {
        $product_id = (int)($_GET['product_id'] ?? 0);
        $store_id   = (int)($_GET['store_id'] ?? 0);
        $page       = max(1, (int)($_GET['page'] ?? 1));
        $limit      = 10;
        $offset     = ($page - 1) * $limit;

        if ($product_id <= 0) sendResponse(false, 'product_id required');

        // Average rating
        $avg = $conn->prepare("SELECT AVG(rating) avg_rating, COUNT(*) total FROM product_reviews WHERE product_id = ? AND status = 'approved'");
        // PDO bind
$avg->execute([$product_id]);$avg->execute();
        $stats = $avg->fetch(PDO::FETCH_ASSOC);
        

        // Approved reviews
        $s = $conn->prepare("
            SELECT r.review_id, r.rating, r.review_text, r.is_verified_purchase, r.created_at,
                   c.full_name AS reviewer_name
            FROM product_reviews r
            JOIN customers c ON r.customer_id = c.customer_id
            WHERE r.product_id = ? AND r.status = 'approved'
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ");
        // PDO bind
$s->execute([$product_id, $limit, $offset]);$s->execute();
        $reviews = $s->fetchAll(PDO::FETCH_ASSOC);
        

        sendResponse(true, 'Reviews loaded', [
            'avg_rating'  => round((float)$stats['avg_rating'], 1),
            'total'       => (int)$stats['total'],
            'reviews'     => $reviews,
            'page'        => $page,
        ]);

    } elseif ($method === 'POST') {
        $data = getJSONInput();
        validateRequired($data, ['customer_id', 'product_id', 'store_id', 'rating']);

        $customer_id = (int)$data['customer_id'];
        $product_id  = (int)$data['product_id'];
        $store_id    = (int)$data['store_id'];
        $order_id    = isset($data['order_id']) ? (int)$data['order_id'] : null;
        $rating      = min(5, max(1, (int)$data['rating']));
        $review_text = sanitizeString($data['review_text'] ?? '');

        // Verify purchase if order_id given
        $is_verified = 0;
        if ($order_id) {
            $v = $conn->prepare("SELECT 1 FROM customer_order_items oi JOIN customer_orders co ON oi.order_id = co.order_id WHERE oi.product_id = ? AND co.customer_id = ? AND co.order_id = ? AND co.status = 'delivered'");
            // PDO bind
$v->execute([$product_id, $customer_id, $order_id]);$v->execute();
            $is_verified = $v->rowCount() > 0 ? 1 : 0;
            
        }

        $s = $conn->prepare("INSERT INTO product_reviews (product_id, customer_id, store_id, order_id, rating, review_text, is_verified_purchase, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
        // PDO bind
$s->execute([$product_id, $customer_id, $store_id, $order_id, $rating, $review_text, $is_verified]);$s->execute();
        $review_id = $conn->lastInsertId();
        

        sendResponse(true, 'Review submitted. It will be visible after approval.', ['review_id' => $review_id]);

    } elseif ($method === 'PUT') {
        $data = getJSONInput();
        validateRequired($data, ['review_id', 'status', 'store_id']);

        $review_id = (int)$data['review_id'];
        $status    = $data['status'];
        $store_id  = (int)$data['store_id'];

        if (!in_array($status, ['approved', 'rejected'])) sendResponse(false, 'Invalid status');

        $s = $conn->prepare("UPDATE product_reviews SET status = ? WHERE review_id = ? AND store_id = ?");
        // PDO bind
$s->execute([$status, $review_id, $store_id]);$s->execute();
        

        sendResponse(true, 'Review ' . $status);
    } else {
        sendResponse(false, 'Method not allowed');
    }

} catch (Exception $e) {
    logError('product_reviews: ' . $e->getMessage());
    sendResponse(false, 'Error: ' . $e->getMessage());
}


?>
