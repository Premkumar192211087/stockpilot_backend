<?php
/**
 * StockPilot API - Core Configuration (Supabase PostgreSQL & Tokens)
 * Centralized configuration, Supabase connection, credentials & utility functions
 */

// Disable error display in production (enable for debugging)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// =========================================================================
// 1. SUPABASE DATABASE (POSTGRESQL) CONNECTION SETTINGS
// Obtain these from Supabase Dashboard -> Project Settings -> Database
// =========================================================================

// Host: Direct 'db.fwygiewtjqiukqvqflyb.supabase.co' or Pooler 'aws-0-ap-south-1.pooler.supabase.com'
define('DB_HOST', getenv('DB_HOST') ?: 'db.fwygiewtjqiukqvqflyb.supabase.co');

// Port: 5432 (Direct / Session Pooler) or 6543 (Transaction Pooler)
define('DB_PORT', getenv('DB_PORT') ?: '5432');

// User: 'postgres' (Direct) or 'postgres.fwygiewtjqiukqvqflyb' (Pooler)
define('DB_USER', getenv('DB_USER') ?: 'postgres');

// Database Password: Set in Supabase Dashboard (Project Settings -> Database)
define('DB_PASS', getenv('DB_PASS') ?: 'Nani@37516149');

// Default database name in Supabase is always 'postgres'
define('DB_NAME', getenv('DB_NAME') ?: 'postgres');

// SSL Mode: Supabase REQUIRES SSL mode enabled for all external connections
define('DB_SSLMODE', getenv('DB_SSLMODE') ?: 'require');

// =========================================================================
// 2. SUPABASE API TOKENS & KEYS (For REST API, Auth & Storage)
// Obtain these from Supabase Dashboard -> Project Settings -> API
// =========================================================================

// Project URL: https://fwygiewtjqiukqvqflyb.supabase.co
define('SUPABASE_URL', getenv('SUPABASE_URL') ?: 'https://fwygiewtjqiukqvqflyb.supabase.co');

// Anon Key: Public key for client apps
define('SUPABASE_ANON_KEY', getenv('SUPABASE_ANON_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZ3eWdpZXd0anFpdWtxdnFmbHliIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODUxNzI4NTcsImV4cCI6MjEwMDc0ODg1N30.Zoy5vqWesCSTmbl9rJbAmUgyuP6758e76wkG8Svhx5c');

// Service Role Key: Secret key for server-side administrative access (bypasses RLS)
define('SUPABASE_SERVICE_ROLE_KEY', getenv('SUPABASE_SERVICE_ROLE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZ3eWdpZXd0anFpdWtxdnFmbHliIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc4NTE3Mjg1NywiZXhwIjoyMTAwNzQ4ODU3fQ.FwR-IypGYsD23G6_j319qH-mZsKmjKVG2XwxFc7BOFM');

// JWT Secret: Used to decode/verify Supabase Auth JWT tokens if integrating Supabase Auth
define('SUPABASE_JWT_SECRET', getenv('SUPABASE_JWT_SECRET') ?: 'YOUR_SUPABASE_JWT_SECRET');

/**
 * Get PostgreSQL database connection (PDO for Supabase)
 * @return PDO Database connection instance
 */
function getDBConnection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host    = DB_HOST;
    $port    = DB_PORT;
    $dbname  = DB_NAME;
    $user    = DB_USER;
    $pass    = DB_PASS;
    $sslmode = DB_SSLMODE;

    // Supabase requires sslmode=require
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=$sslmode";
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        logError("Supabase DB Connection failed: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Supabase Database connection failed. Please verify DB_HOST, DB_USER, and DB_PASS in config.php.'
        ]);
        exit();
    }
}

/**
 * Helper: Get default HTTP headers for Supabase REST API requests
 * @param bool $useServiceRole True to use admin service role key, false for anon key
 * @return array Header array for cURL / HTTP client
 */
function getSupabaseApiHeaders($useServiceRole = true) {
    $apiKey = $useServiceRole ? SUPABASE_SERVICE_ROLE_KEY : SUPABASE_ANON_KEY;
    return [
        'apikey: ' . $apiKey,
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ];
}

/**
 * Send standardized JSON response
 * @param bool $success Success status
 * @param string $message Response message
 * @param mixed $data Optional data payload
 */
function sendResponse($success, $message, $data = null) {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    if (!defined('BATCH_MODE')) {
        exit();
    }
}

/**
 * Get and decode JSON input from request body
 * @return array Decoded JSON data
 */
function getJSONInput() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        sendResponse(false, 'Invalid JSON input: ' . json_last_error_msg());
    }
    
    return $data ?? [];
}

/**
 * Validate required fields in data array
 * @param array $data Input data
 * @param array $required_fields List of required field names
 */
function validateRequired($data, $required_fields) {
    $missing = [];
    
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        sendResponse(false, 'Missing required fields: ' . implode(', ', $missing));
    }
}

/**
 * Sanitize string input
 * @param string $str Input string
 * @return string Sanitized string
 */
function sanitizeString($str) {
    return htmlspecialchars(strip_tags(trim($str)), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email format
 * @param string $email Email address
 * @return bool True if valid
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Log error to file
 * @param string $message Error message
 */
function logError($message) {
    $logFile = __DIR__ . '/error.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Set default headers for all API responses
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 3600');

// Handle OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
?>
