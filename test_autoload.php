<?php
require_once __DIR__ . '/vendor/autoload.php';

try {
    $class = new Hempies\COA\Hempies_COA_Airtable();
    echo "Class loaded successfully!\n";
    var_dump($class);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    
    // Debug information
    echo "\nAutoloader paths:\n";
    var_dump(get_included_files());
    
    echo "\nComposer autoloader exists: " . (file_exists(__DIR__ . '/vendor/autoload.php') ? 'Yes' : 'No') . "\n";
    echo "Class file exists: " . (file_exists(__DIR__ . '/src/Hempies_COA_Airtable.php') ? 'Yes' : 'No') . "\n";
} 