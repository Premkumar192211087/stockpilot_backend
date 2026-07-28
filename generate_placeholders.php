<?php
/**
 * StockPilot — Generate Placeholder Product Images
 * 
 * Creates simple colored placeholder images for products that don't have
 * generated images yet. Each gets a unique color based on its category.
 * 
 * Run via browser: http://192.168.0.3/api_legacy/generate_placeholders.php
 */

$uploadsDir = __DIR__ . '/uploads/products/';

// Products that need placeholder images (those not yet generated)
$products = [
    ['filename' => 'laptop_sleeve.png',          'name' => 'Laptop Sleeve 15"',       'category' => 'Accessories'],
    ['filename' => 'lenovo_tab_m10.png',          'name' => 'Lenovo Tab M10 Plus',     'category' => 'Mobile Devices'],
    ['filename' => 'logitech_k380.png',           'name' => 'Logitech K380 Keyboard',  'category' => 'Accessories'],
    ['filename' => 'nintendo_switch_oled.png',    'name' => 'Nintendo Switch OLED',     'category' => 'Gaming'],
    ['filename' => 'playstation_5.png',           'name' => 'PlayStation 5',            'category' => 'Gaming'],
    ['filename' => 'razer_deathadder_v3.png',     'name' => 'Razer DeathAdder V3',      'category' => 'Gaming'],
    ['filename' => 'samsung_256gb_microsd.png',   'name' => 'Samsung 256GB microSD',    'category' => 'Accessories'],
    ['filename' => 'samsung_galaxy_buds_fe.png',  'name' => 'Samsung Galaxy Buds FE',   'category' => 'Audio'],
    ['filename' => 'smart_led_bulb.png',          'name' => 'Smart LED Bulb',           'category' => 'Smart Home'],
    ['filename' => 'sony_wh1000xm5.png',          'name' => 'Sony WH-1000XM5',         'category' => 'Audio'],
    ['filename' => 'tplink_wifi_router.png',      'name' => 'TP-Link WiFi Router',      'category' => 'Smart Home'],
    ['filename' => 'usb_c_cable_6ft.png',         'name' => 'USB-C Cable 6ft',          'category' => 'Cables'],
    ['filename' => 'usb_c_hub_6in1.png',          'name' => 'USB-C Hub 6-in-1',         'category' => 'Accessories'],
    ['filename' => 'wireless_charger_pad.png',    'name' => 'Wireless Charger Pad',     'category' => 'Chargers'],
];

// Category color palette
$categoryColors = [
    'Accessories'    => ['bg' => [37, 99, 235],  'fg' => [255, 255, 255]],  // Blue
    'Mobile Devices' => ['bg' => [16, 163, 74],  'fg' => [255, 255, 255]],  // Green
    'Gaming'         => ['bg' => [124, 58, 237], 'fg' => [255, 255, 255]],  // Purple
    'Audio'          => ['bg' => [234, 88, 12],  'fg' => [255, 255, 255]],  // Orange
    'Smart Home'     => ['bg' => [20, 184, 166], 'fg' => [255, 255, 255]],  // Teal
    'Cables'         => ['bg' => [100, 116, 139],'fg' => [255, 255, 255]],  // Slate
    'Chargers'       => ['bg' => [220, 38, 38],  'fg' => [255, 255, 255]],  // Red
];

$created = 0;
$results = [];

foreach ($products as $product) {
    $filepath = $uploadsDir . $product['filename'];
    
    // Skip if file already exists
    if (file_exists($filepath)) {
        $results[] = $product['filename'] . ' — already exists, skipped';
        continue;
    }

    // Create 400x400 image
    $img = imagecreatetruecolor(400, 400);
    
    $colors = $categoryColors[$product['category']] ?? ['bg' => [37, 99, 235], 'fg' => [255, 255, 255]];
    
    // Background
    $bg = imagecolorallocate($img, $colors['bg'][0], $colors['bg'][1], $colors['bg'][2]);
    imagefill($img, 0, 0, $bg);
    
    // Lighter center area
    $lighter = imagecolorallocatealpha($img, 255, 255, 255, 100);
    imagefilledrectangle($img, 40, 40, 360, 360, $lighter);
    
    // Text color
    $fg = imagecolorallocate($img, $colors['fg'][0], $colors['fg'][1], $colors['fg'][2]);
    $shadow = imagecolorallocate($img, 0, 0, 0);
    
    // Product name (centered, wrapped)
    $name = $product['name'];
    $fontSize = 5; // Built-in font size
    
    // Word wrap for long names
    $words = explode(' ', $name);
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        if (strlen($line . ' ' . $word) > 18) {
            $lines[] = trim($line);
            $line = $word;
        } else {
            $line .= ' ' . $word;
        }
    }
    $lines[] = trim($line);
    
    $lineHeight = 20;
    $totalHeight = count($lines) * $lineHeight;
    $startY = (400 - $totalHeight) / 2;
    
    foreach ($lines as $i => $textLine) {
        $textWidth = strlen($textLine) * imagefontwidth($fontSize);
        $x = (400 - $textWidth) / 2;
        $y = $startY + ($i * $lineHeight);
        imagestring($img, $fontSize, $x + 1, $y + 1, $textLine, $shadow);
        imagestring($img, $fontSize, $x, $y, $textLine, $fg);
    }
    
    // Category label at bottom
    $catWidth = strlen($product['category']) * imagefontwidth(3);
    imagestring($img, 3, (400 - $catWidth) / 2, 350, $product['category'], $fg);
    
    // Save
    imagepng($img, $filepath);
    imagedestroy($img);
    
    $created++;
    $results[] = $product['filename'] . ' — created';
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => "Created $created placeholder images",
    'results' => $results
], JSON_PRETTY_PRINT);
?>
