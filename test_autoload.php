<?php
require_once __DIR__ . '/vendor/autoload.php';

try {
    $sync = new Hempies\COA\Hempies_COA_Airtable();
    echo "Class loaded successfully!\n";
    
    // Test Square connection
    $square_status = $sync->test_square_connection();
    echo "Square connection test: " . json_encode($square_status, JSON_PRETTY_PRINT) . "\n";
    
    // Test Airtable connection
    $airtable_status = $sync->test_airtable_connection();
    echo "Airtable connection test: " . json_encode($airtable_status, JSON_PRETTY_PRINT) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    
    // Debug information
    echo "\nDebug Information:\n";
    echo "Autoloader exists: " . (file_exists(__DIR__ . '/vendor/autoload.php') ? 'Yes' : 'No') . "\n";
    echo "Class file exists: " . (file_exists(__DIR__ . '/src/Hempies_COA_Airtable.php') ? 'Yes' : 'No') . "\n";
    echo "Current directory: " . __DIR__ . "\n";
    echo "Included files:\n";
    print_r(get_included_files());
} 