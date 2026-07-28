<?php
/**
 * StockPilot — Bulk Product Image Updater
 * 
 * This script:
 * 1. Maps each product ID to its generated image filename
 * 2. Updates the image_url column in the products table
 * 
 * Run via browser: http://192.168.0.3/api_legacy/update_product_images.php
 */

require_once 'config.php';

$conn = getDBConnection();

// Mapping: product_id => image filename (in uploads/products/)
$imageMap = [
    // Already existing images (keep as-is)
    1  => 'iphone15pro.png',           // iPhone 15 Pro
    2  => 'samsung_s24.png',           // Samsung Galaxy S24
    3  => 'macbook_air.png',           // MacBook Air M3

    // Newly generated images
    13  => 'anker_powercore_20000.png', // Anker PowerCore 20000
    17  => 'apple_airpods_pro_2.png',   // Apple AirPods Pro 2
    101 => 'bluetooth_speaker.png',     // Bluetooth Speaker
    4   => 'dell_xps_13.png',          // Dell XPS 13
    102 => 'gaming_mouse.png',         // Gaming Mouse
    20  => 'google_pixel_buds_a.png',  // Google Pixel Buds A
    9   => 'iphone_15_case.png',       // iPhone 15 Case
    15  => 'jbl_flip_6.png',           // JBL Flip 6 Speaker

    // Remaining products — will use descriptive named placeholders
    // (images to be generated later)
    103 => 'laptop_sleeve.png',         // Laptop Sleeve 15 inch
    19  => 'lenovo_tab_m10.png',        // Lenovo Tab M10 Plus
    11  => 'logitech_k380.png',         // Logitech K380 Keyboard
    7   => 'nintendo_switch_oled.png',  // Nintendo Switch OLED
    6   => 'playstation_5.png',         // PlayStation 5
    16  => 'razer_deathadder_v3.png',   // Razer DeathAdder V3
    12  => 'samsung_256gb_microsd.png', // Samsung 256GB microSD
    18  => 'samsung_galaxy_buds_fe.png',// Samsung Galaxy Buds FE
    105 => 'smart_led_bulb.png',        // Smart LED Bulb
    5   => 'sony_wh1000xm5.png',       // Sony WH-1000XM5
    14  => 'tplink_wifi_router.png',    // TP-Link WiFi Router
    8   => 'usb_c_cable_6ft.png',       // USB-C Cable 6ft
    104 => 'usb_c_hub_6in1.png',        // USB-C Hub 6-in-1
    10  => 'wireless_charger_pad.png',  // Wireless Charger Pad
];

$updated = 0;
$failed  = 0;
$results = [];

foreach ($imageMap as $productId => $filename) {
    $imageUrl = 'uploads/products/' . $filename;

    $stmt = $conn->prepare("UPDATE products SET image_url = ? WHERE id = ?");
    // PDO bind
$stmt->execute([$imageUrl, $productId]);if ($stmt->execute() && $stmt->affected_rows > 0) {
        $updated++;
        $results[] = [
            'product_id' => $productId,
            'image_url'  => $imageUrl,
            'status'     => 'updated'
        ];
    } else {
        $failed++;
        $results[] = [
            'product_id' => $productId,
            'image_url'  => $imageUrl,
            'status'     => $stmt->affected_rows === 0 ? 'no_change' : 'failed'
        ];
    }
    
}



// Output results
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => "Updated $updated products, $failed unchanged/failed",
    'results' => $results
], JSON_PRETTY_PRINT);
?>
