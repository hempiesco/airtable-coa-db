<?php
header('Content-Type: application/json');

echo json_encode([
    'status' => 'success',
    'message' => 'Server is running',
    'time' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'environment' => [
        'square_token_set' => !empty($_ENV['SQUARE_ACCESS_TOKEN']),
        'airtable_key_set' => !empty($_ENV['AIRTABLE_API_KEY']),
        'airtable_base_set' => !empty($_ENV['AIRTABLE_BASE_ID'])
    ]
]); 