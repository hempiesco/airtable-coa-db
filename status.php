<?php
require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json');

try {
    $sync = new Hempies\COA\Hempies_COA_Airtable();
    
    // Test Square connection
    $square_status = $sync->test_square_connection();
    
    // Test Airtable connection
    $airtable_status = $sync->test_airtable_connection();
    
    // Get sync status
    $sync_status = $sync->get_sync_status();
    
    echo json_encode([
        'status' => 'success',
        'time' => date('Y-m-d H:i:s'),
        'connections' => [
            'square' => $square_status,
            'airtable' => $airtable_status
        ],
        'sync' => $sync_status,
        'environment' => [
            'square_token_set' => !empty($_ENV['SQUARE_ACCESS_TOKEN']),
            'airtable_key_set' => !empty($_ENV['AIRTABLE_API_KEY']),
            'airtable_base_set' => !empty($_ENV['AIRTABLE_BASE_ID']),
            'airtable_table_set' => !empty($_ENV['AIRTABLE_TABLE_NAME'])
        ]
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
} 