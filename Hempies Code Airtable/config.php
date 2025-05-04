<?php
/**
 * Configuration file for Hempie's COA Airtable Sync
 */

return array(
    // Categories to exclude from sync (can be IDs or names)
    'excluded_categories' => array(
        // Add your excluded categories here
        // Example: 'Gift Cards',
        // Example: '1234567890', // Square category ID
    ),
    
    // Airtable table configuration
    'airtable' => array(
        'table_name' => 'Products', // Default table name
        'fields' => array(
            'SKU' => 'Single line text',
            'Name' => 'Single line text',
            'Status' => 'Single select',
            'Quantity' => 'Number',
            'Category' => 'Single line text',
            'Created' => 'DateTime',
            'Last Updated' => 'DateTime'
        )
    )
); 