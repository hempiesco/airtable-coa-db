<?php
/**
 * Hempie's COA Airtable Sync
 * Description: Syncs inventory from Square to Airtable for COA management
 * Version: 1.0.0
 */

class Hempies_COA_Airtable {
    // Singleton instance
    private static $instance = null;
    
    // API credentials
    private $square_access_token = '';
    private $airtable_api_key = '';
    private $airtable_base_id = '';
    private $airtable_table_name = '';
    
    // Excluded categories (IDs from Square)
    private $excluded_categories = array();
    
    // Sync status
    private $sync_running = false;
    private $sync_log = array();
    private $sync_total = 0;
    private $sync_processed = 0;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Load configuration
        $this->load_config();
        
        // Initialize sync status
        $this->sync_running = false;
        $this->sync_log = array();
        $this->sync_total = 0;
        $this->sync_processed = 0;
    }
    
    /**
     * Load configuration from environment variables or config file
     */
    private function load_config() {
        // Load from environment variables if available
        $this->square_access_token = getenv('SQUARE_ACCESS_TOKEN') ?: '';
        $this->airtable_api_key = getenv('AIRTABLE_API_KEY') ?: '';
        $this->airtable_base_id = getenv('AIRTABLE_BASE_ID') ?: '';
        $this->airtable_table_name = getenv('AIRTABLE_TABLE_NAME') ?: 'Products';
        
        // Load excluded categories from config file if exists
        $config_file = __DIR__ . '/config.php';
        if (file_exists($config_file)) {
            $config = include $config_file;
            if (isset($config['excluded_categories'])) {
                $this->excluded_categories = $config['excluded_categories'];
            }
        }
    }
    
    /**
     * Start a sync operation
     */
    public function start_sync($test_mode = false) {
        // Check if a sync is already running
        if ($this->sync_running) {
            $this->log('Sync already in progress. Please wait for it to complete or stop it first.');
            return;
        }
        
        // Make sure we have API tokens
        if (empty($this->square_access_token)) {
            $this->log('Error: Square API token is missing.');
            return;
        }
        if (empty($this->airtable_api_key)) {
            $this->log('Error: Airtable API key is missing.');
            return;
        }
        if (empty($this->airtable_base_id)) {
            $this->log('Error: Airtable Base ID is missing.');
            return;
        }
        
        // Reset sync status
        $this->sync_running = true;
        $this->sync_log = array();
        $this->sync_total = 0;
        $this->sync_processed = 0;
        
        // Fetch SKUs from Square
        $skus = $this->fetch_square_items();
        
        if (empty($skus)) {
            $this->log('Error: No SKUs fetched from Square. Check API credentials and try again.');
            $this->sync_running = false;
            return;
        }
        
        // If test mode, only use the first 5 SKUs
        if ($test_mode) {
            $skus = array_slice($skus, 0, 5);
            $this->log('Test mode: Limited to 5 SKUs');
        }
        
        // Process items
        $this->sync_total = count($skus);
        $this->process_items($skus);
        
        // Mark sync as complete
        $this->sync_running = false;
    }
    
    /**
     * Process a batch of items
     */
    private function process_items($items) {
        foreach ($items as $sku_data) {
            $this->process_sku($sku_data);
            $this->sync_processed++;
            
            // Log progress
            $percentage = round(($this->sync_processed / $this->sync_total) * 100);
            $this->log("Processed {$this->sync_processed}/{$this->sync_total} items ({$percentage}%)");
        }
    }
    
    /**
     * Process a single SKU
     */
    private function process_sku($sku_data) {
        // Extract data
        $sku = isset($sku_data['id']) ? $sku_data['id'] : '';
        $name = isset($sku_data['name']) ? $sku_data['name'] : '';
        $parent_name = isset($sku_data['parent_name']) ? $sku_data['parent_name'] : '';
        $quantity = isset($sku_data['quantity']) ? intval($sku_data['quantity']) : 0;
        $category = isset($sku_data['category']) ? $sku_data['category'] : '';
        $category_name = isset($sku_data['category_name']) ? $sku_data['category_name'] : '';
        $category_ids = isset($sku_data['category_ids']) ? $sku_data['category_ids'] : array();
        $category_names = isset($sku_data['category_names']) ? $sku_data['category_names'] : array();
        $is_archived = isset($sku_data['is_archived']) ? $sku_data['is_archived'] : false;
        
        // Create a full product name
        $full_product_name = $parent_name;
        if (!empty($name) && $name !== $parent_name) {
            $full_product_name .= ' - ' . $name;
        }
        
        // Log the SKU being processed
        $this->log("Processing SKU: {$sku} - {$full_product_name} (Qty: {$quantity}, Archived: " . ($is_archived ? 'Yes' : 'No') . ")");
        
        // Skip if archived
        if ($is_archived) {
            $this->log("Skipped: {$sku} - Item is archived in Square");
            $this->update_airtable_record($sku, array(
                'Status' => 'Archived',
                'Quantity' => 0
            ));
            return;
        }
        
        // Skip if any category is in excluded list
        $should_skip = false;
        
        // Check categories
        if (!empty($category_ids) && is_array($category_ids)) {
            foreach ($category_ids as $index => $cat_id) {
                $cat_name = isset($category_names[$index]) ? $category_names[$index] : '';
                
                if (in_array($cat_id, $this->excluded_categories) || in_array($cat_name, $this->excluded_categories)) {
                    $this->log("Skipped: {$sku} - In excluded category: {$cat_name}");
                    $this->update_airtable_record($sku, array(
                        'Status' => 'Excluded',
                        'Quantity' => $quantity
                    ));
                    return;
                }
            }
        }
        
        // Skip if quantity is 0 or less
        if ($quantity <= 0) {
            $this->log("Skipped: {$sku} - Out of stock (Qty: {$quantity})");
            $this->update_airtable_record($sku, array(
                'Status' => 'Out of Stock',
                'Quantity' => 0
            ));
            return;
        }
        
        // Check if record exists in Airtable
        $record = $this->get_airtable_record($sku);
        
        if ($record) {
            // Update existing record
            $this->log("Updating existing record for SKU: {$sku}");
            $this->update_airtable_record($sku, array(
                'Name' => $full_product_name,
                'Status' => 'Active',
                'Quantity' => $quantity,
                'Category' => $category_name,
                'Last Updated' => date('Y-m-d H:i:s')
            ));
        } else {
            // Create new record
            $this->log("Creating new record for SKU: {$sku}");
            $this->create_airtable_record(array(
                'SKU' => $sku,
                'Name' => $full_product_name,
                'Status' => 'Active',
                'Quantity' => $quantity,
                'Category' => $category_name,
                'Created' => date('Y-m-d H:i:s'),
                'Last Updated' => date('Y-m-d H:i:s')
            ));
        }
    }
    
    /**
     * Get record from Airtable by SKU
     */
    private function get_airtable_record($sku) {
        $endpoint = "https://api.airtable.com/v0/{$this->airtable_base_id}/{$this->airtable_table_name}";
        $headers = array(
            'Authorization' => 'Bearer ' . $this->airtable_api_key,
            'Content-Type' => 'application/json'
        );
        
        $params = array(
            'filterByFormula' => "{SKU} = '{$sku}'"
        );
        
        $endpoint .= '?' . http_build_query($params);
        
        $response = $this->make_request('GET', $endpoint, $headers);
        
        if ($response && isset($response['records']) && !empty($response['records'])) {
            return $response['records'][0];
        }
        
        return null;
    }
    
    /**
     * Create new record in Airtable
     */
    private function create_airtable_record($fields) {
        $endpoint = "https://api.airtable.com/v0/{$this->airtable_base_id}/{$this->airtable_table_name}";
        $headers = array(
            'Authorization' => 'Bearer ' . $this->airtable_api_key,
            'Content-Type' => 'application/json'
        );
        
        $data = array(
            'records' => array(
                array(
                    'fields' => $fields
                )
            )
        );
        
        $response = $this->make_request('POST', $endpoint, $headers, json_encode($data));
        
        if ($response && isset($response['records']) && !empty($response['records'])) {
            return $response['records'][0];
        }
        
        return null;
    }
    
    /**
     * Update record in Airtable
     */
    private function update_airtable_record($sku, $fields) {
        $record = $this->get_airtable_record($sku);
        
        if (!$record) {
            return false;
        }
        
        $endpoint = "https://api.airtable.com/v0/{$this->airtable_base_id}/{$this->airtable_table_name}/{$record['id']}";
        $headers = array(
            'Authorization' => 'Bearer ' . $this->airtable_api_key,
            'Content-Type' => 'application/json'
        );
        
        $data = array(
            'fields' => $fields
        );
        
        $response = $this->make_request('PATCH', $endpoint, $headers, json_encode($data));
        
        return $response !== null;
    }
    
    /**
     * Make HTTP request
     */
    private function make_request($method, $endpoint, $headers, $body = null) {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->format_headers($headers));
        
        if ($body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $this->log('Error making request: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        
        curl_close($ch);
        
        if ($http_code >= 200 && $http_code < 300) {
            return json_decode($response, true);
        }
        
        $this->log("Error: API returned status {$http_code}");
        return null;
    }
    
    /**
     * Format headers for cURL
     */
    private function format_headers($headers) {
        $formatted = array();
        foreach ($headers as $key => $value) {
            $formatted[] = "{$key}: {$value}";
        }
        return $formatted;
    }
    
    /**
     * Add a log message
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $this->sync_log[] = array(
            'time' => $timestamp,
            'message' => $message
        );
        
        // Also log to error log for debugging
        error_log('[Hempies COA Airtable] ' . $message);
    }
    
    /**
     * Get sync status
     */
    public function get_sync_status() {
        return array(
            'is_running' => $this->sync_running,
            'log' => $this->sync_log,
            'total' => $this->sync_total,
            'processed' => $this->sync_processed,
            'percentage' => ($this->sync_total > 0) ? round(($this->sync_processed / $this->sync_total) * 100) : 0
        );
    }
    
    /**
     * Fetch items from Square API
     */
    private function fetch_square_items() {
        $items = array();
        $cursor = null;
        $has_more = true;
        
        while ($has_more) {
            $endpoint = 'https://connect.squareup.com/v2/catalog/list';
            $headers = array(
                'Authorization' => 'Bearer ' . $this->square_access_token,
                'Content-Type' => 'application/json'
            );
            
            $params = array(
                'types' => 'ITEM'
            );
            
            if ($cursor) {
                $params['cursor'] = $cursor;
            }
            
            $endpoint .= '?' . http_build_query($params);
            
            $response = $this->make_request('GET', $endpoint, $headers);
            
            if (!$response || !isset($response['objects']) || empty($response['objects'])) {
                break;
            }
            
            // Process items
            foreach ($response['objects'] as $item) {
                // Skip items without categories
                if (empty($item['item_data']['category_id'])) {
                    $this->log("Skipping item {$item['id']} - No category assigned");
                    continue;
                }
                
                // Process variations
                if (isset($item['item_data']['variations']) && is_array($item['item_data']['variations'])) {
                    foreach ($item['item_data']['variations'] as $variation) {
                        if (isset($variation['item_variation_data']['sku'])) {
                            $sku = $variation['item_variation_data']['sku'];
                            $items[$sku] = array(
                                'id' => $variation['id'],
                                'name' => $item['item_data']['name'],
                                'parent_name' => $item['item_data']['name'],
                                'category_id' => $item['item_data']['category_id'],
                                'category_name' => isset($item['item_data']['category_data']['name']) ? $item['item_data']['category_data']['name'] : '',
                                'is_archived' => isset($item['item_data']['is_archived']) ? $item['item_data']['is_archived'] : false
                            );
                        }
                    }
                }
            }
            
            // Check if there are more items
            $has_more = isset($response['cursor']) && !empty($response['cursor']);
            $cursor = $has_more ? $response['cursor'] : null;
        }
        
        return $items;
    }
}

// Create a simple CLI interface
if (php_sapi_name() === 'cli') {
    $sync = Hempies_COA_Airtable::get_instance();
    
    // Parse command line arguments
    $options = getopt('', array('test::'));
    $test_mode = isset($options['test']);
    
    // Start sync
    $sync->start_sync($test_mode);
    
    // Print final status
    $status = $sync->get_sync_status();
    echo "\nSync completed!\n";
    echo "Processed {$status['processed']}/{$status['total']} items ({$status['percentage']}%)\n";
    
    // Print log
    echo "\nLog:\n";
    foreach ($status['log'] as $entry) {
        echo "[{$entry['time']}] {$entry['message']}\n";
    }
} 