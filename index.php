<?php
require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables if .env file exists
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize the sync class
$sync = new Hempies\COA\Hempies_COA_Airtable();

// Handle CLI mode
if (php_sapi_name() === 'cli') {
    $test_mode = in_array('--test', $argv);
    $sync->start_sync($test_mode);
    exit;
}

// Handle web requests
header('Content-Type: application/json');

try {
    // Check if it's a sync request
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $test_mode = isset($data['test_mode']) && $data['test_mode'];
        
        $sync->start_sync($test_mode);
        echo json_encode(['status' => 'success', 'message' => 'Sync started']);
    } 
    // Check sync status
    else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $status = $sync->get_sync_status();
        echo json_encode($status);
    }
    else {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
} 