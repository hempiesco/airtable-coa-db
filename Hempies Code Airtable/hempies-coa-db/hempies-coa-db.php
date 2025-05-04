<?php
/**
 * Plugin Name: Hempie's COA DB Sync
 * Description: Syncs inventory from Square to WordPress for COA management
 * Version: 1.0.1
 * Author: Your Name
 * Text Domain: hempies-coa-db
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include COA Dashboard
require_once plugin_dir_path(__FILE__) . 'includes/coa-dashboard/class-hempies-coa-dashboard.php';

// Initialize the plugin
function hempies_coa_db_init() {
    Hempies_COA_DB::get_instance();
    
    // Add custom cron interval
    add_filter('cron_schedules', 'hempies_coa_db_cron_intervals');
}

// Only initialize the plugin in admin or during cron jobs
if (is_admin() || (defined('DOING_CRON') && DOING_CRON)) {
    add_action('plugins_loaded', 'hempies_coa_db_init');
}

/**
 * Add custom cron intervals
 */
function hempies_coa_db_cron_intervals($schedules) {
    $schedules['minute'] = array(
        'interval' => 60,
        'display' => __('Every Minute')
    );
    return $schedules;
}

// Define plugin constants
define('HEMPIES_COA_DB_VERSION', '1.0.1');
define('HEMPIES_COA_DB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HEMPIES_COA_DB_PLUGIN_URL', plugin_dir_url(__FILE__));

class Hempies_COA_DB {
    // Plugin singleton instance
    private static $instance = null;
    
    // Flag to track if categories have been logged
    private static $categories_logged = false;
    
    // Square API credentials
    private $square_access_token = '';
    private $notification_email = '';
    
    // Excluded categories (IDs from Square)
    private $excluded_categories = array();
    
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
     * AJAX handler for getting sync status
     */
    public function ajax_get_sync_status() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Get sync info
        $is_running = get_transient('hempies_sync_running');
        $log = get_transient('hempies_sync_log');
        $total = get_option('hempies_sync_total', 0);
        $processed = get_option('hempies_sync_processed', 0);
        
        // Calculate progress percentage
        $percentage = ($total > 0) ? round(($processed / $total) * 100) : 0;
        
        // Prepare response
        $response = array(
            'is_running' => $is_running,
            'log' => $log,
            'total' => $total,
            'processed' => $processed,
            'percentage' => $percentage
        );
        
        wp_send_json_success($response);
    }
    
    /**
     * Add a log message
     */
    private function log($message) {
        // Get current log
        $log = get_transient('hempies_sync_log');
        if (!is_array($log)) {
            $log = array();
        }
        
        // Add timestamp
        $timestamp = current_time('mysql');
        $log[] = array(
            'time' => $timestamp,
            'message' => $message
        );
        
        // Limit log size to last 100 entries
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }
        
        // Save updated log
        set_transient('hempies_sync_log', $log, 0);
        
        // Also log to error log for debugging
        error_log('[Hempies COA DB] ' . $message);
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Hempie's COA DB Sync</h1>
            
            <div class="card">
                <h2>Sync Products from Square</h2>
                
                <form method="post" action="">
                    <?php wp_nonce_field('hempies_sync', 'hempies_sync_nonce'); ?>
                    
                    <div class="sync-actions">
                        <button type="submit" name="sync_mode" value="start_full" class="button button-primary">
                            Start Full Sync
                        </button>
                        
                        <button type="submit" name="sync_mode" value="test_five" class="button button-secondary">
                            Test 5 SKUs
                        </button>
                        
                        <button type="submit" name="sync_mode" value="stop" class="button button-secondary">
                            Stop Sync
                        </button>
                        
                        <button type="submit" name="sync_mode" value="force_process" class="button button-secondary">
                            Force Process Queue
                        </button>
                        
                        <button type="submit" name="sync_mode" value="reset_cron" class="button button-secondary">
                            Reset Cron Job
                        </button>
                    </div>
                </form>
                
                <div class="sync-status">
                    <h3>Sync Progress</h3>
                    <div class="progress-bar-container">
                        <div class="progress-bar" style="width: 0%"></div>
                    </div>
                    <div class="progress-text">0%</div>
                    
                    <div class="cron-status">
                        <h4>Cron Status</h4>
                        <div>
                            Next queue processing run: 
                            <span id="next-cron-run">
                                <?php 
                                $timestamp = wp_next_scheduled('hempies_process_sync_queue');
                                if ($timestamp) {
                                    echo date('Y-m-d H:i:s', $timestamp) . ' (' . human_time_diff($timestamp) . ' from now)';
                                } else {
                                    echo 'Not scheduled';
                                }
                                ?>
                            </span>
                        </div>
                        <div>
                            Next daily automatic sync: 
                            <span id="next-daily-sync">
                                <?php 
                                $timestamp = wp_next_scheduled('hempies_daily_sync');
                                if ($timestamp) {
                                    echo date('Y-m-d H:i:s', $timestamp) . ' (' . human_time_diff($timestamp) . ' from now)';
                                } else {
                                    echo 'Not scheduled';
                                }
                                ?>
                            </span>
                        </div>
                        <div>
                            Current server time: <?php echo current_time('Y-m-d H:i:s'); ?>
                        </div>
                    </div>
                </div>
                
                <div class="sync-log">
                    <h3>Sync Log</h3>
                    <div class="log-container"></div>
                </div>
            </div>
            
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Function to update sync status
                    function updateSyncStatus() {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'hempies_get_sync_status'
                            },
                            success: function(response) {
                                if (response.success) {
                                    var data = response.data;
                                    
                                    // Update progress bar
                                    $('.progress-bar').css('width', data.percentage + '%');
                                    $('.progress-text').text(data.percentage + '% (' + data.processed + '/' + data.total + ')');
                                    
                                    // Update log
                                    var logHtml = '';
                                    if (data.log && data.log.length > 0) {
                                        for (var i = 0; i < data.log.length; i++) {
                                            var logEntry = data.log[i];
                                            logHtml += '<div class="log-entry">';
                                            logHtml += '<span class="log-time">[' + logEntry.time + ']</span> ';
                                            logHtml += '<span class="log-message">' + logEntry.message + '</span>';
                                            logHtml += '</div>';
                                        }
                                    }
                                    $('.log-container').html(logHtml);
                                    
                                    // Scroll to bottom of log
                                    var logContainer = $('.log-container');
                                    logContainer.scrollTop(logContainer.prop('scrollHeight'));
                                    
                                    // Highlight certain log entries
                                    $('.log-entry').each(function() {
                                        var message = $(this).find('.log-message').text();
                                        if (message.includes('Error:') || message.includes('error')) {
                                            $(this).addClass('log-error');
                                        } else if (message.includes('Skipped:')) {
                                            $(this).addClass('log-warning');
                                        } else if (message.includes('Updated:') || message.includes('Created:')) {
                                            $(this).addClass('log-success');
                                        }
                                    });
                                    
                                    // Always schedule next update (even if sync is not running)
                                    // This ensures the log is always updated in real-time
                                    setTimeout(updateSyncStatus, 3000);
                                }
                            }
                        });
                    }
                    
                    // Initial status update
                    updateSyncStatus();
                });
            </script>
            
            <style type="text/css">
                .card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                    padding: 15px;
                    margin-top: 20px;
                }
                
                .sync-actions {
                    margin-bottom: 20px;
                }
                
                .sync-actions .button {
                    margin-right: 10px;
                }
                
                .progress-bar-container {
                    height: 20px;
                    background-color: #f0f0f0;
                    border-radius: 3px;
                    overflow: hidden;
                    margin-bottom: 10px;
                }
                
                .progress-bar {
                    height: 100%;
                    background-color: #0073aa;
                    width: 0%;
                    transition: width 0.5s ease-in-out;
                }
                
                .progress-text {
                    font-size: 14px;
                    margin-bottom: 15px;
                }
                
                .log-container {
                    background-color: #f9f9f9;
                    border: 1px solid #e5e5e5;
                    height: 300px;
                    overflow-y: auto;
                    padding: 10px;
                    font-family: monospace;
                    font-size: 12px;
                }
                
                .log-entry {
                    margin-bottom: 5px;
                    line-height: 1.4;
                }
                
                .log-time {
                    color: #777;
                }
                
                .log-message {
                    color: #333;
                }
                
                .log-error {
                    background-color: #ffebe8;
                    border-left: 3px solid #dc3232;
                    padding-left: 5px;
                }
                
                .log-warning {
                    background-color: #fff8e5;
                    border-left: 3px solid #ffb900;
                    padding-left: 5px;
                }
                
                .log-success {
                    background-color: #ecf7ed;
                    border-left: 3px solid #46b450;
                    padding-left: 5px;
                }
            </style>
        </div>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>COA Sync Settings</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('hempies_coa_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="hempies_coa_square_token">Square API Token</label>
                        </th>
                        <td>
                            <input type="text" id="hempies_coa_square_token" name="hempies_coa_square_token" 
                                value="<?php echo esc_attr(get_option('hempies_coa_square_token')); ?>" class="regular-text" />
                            <p class="description">Your Square API access token for connecting to the catalog API.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="hempies_coa_notification_email">Notification Email</label>
                        </th>
                        <td>
                            <input type="email" id="hempies_coa_notification_email" name="hempies_coa_notification_email" 
                                value="<?php echo esc_attr(get_option('hempies_coa_notification_email', get_option('admin_email'))); ?>" class="regular-text" />
                            <p class="description">Email address to receive notifications when products need COA review.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hempies_coa_enable_emails">Enable Email Notifications</label>
                        </th>
                        <td>
                            <input type="checkbox" id="hempies_coa_enable_emails" name="hempies_coa_enable_emails" 
                                value="1" <?php checked(get_option('hempies_coa_enable_emails', false), true); ?> />
                            <p class="description">Enable email notifications when products need COA review. Disable during initial setup to avoid spam.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hempies_coa_excluded_categories">Excluded Categories</label>
                        </th>
                        <td>
                            <textarea id="hempies_coa_excluded_categories" name="hempies_coa_excluded_categories" 
    class="large-text" rows="5"><?php 
    $excluded_categories = get_option('hempies_coa_excluded_categories', array());
    // Ensure we have an array
    if (!is_array($excluded_categories)) {
        $excluded_categories = array();
    }
    echo esc_textarea(implode("\n", $excluded_categories)); 
    ?></textarea>
<p class="description">Enter Square category IDs or names to exclude, one per line (or comma-separated). Using IDs is recommended for accuracy (e.g. 6NVADA25GKLMZMB336WH3C73 or "Crystals").</p>
<p><strong>Currently excluded:</strong> <span id="excluded-count"><?php echo count($excluded_categories); ?></span> categories</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Load plugin settings
        $this->square_access_token = get_option('hempies_coa_square_token', '');
        $this->notification_email = get_option('hempies_coa_notification_email', get_option('admin_email'));
        
        // Load excluded categories
        $this->excluded_categories = get_option('hempies_coa_excluded_categories', array());
        
        // Clean up excluded categories to remove empty entries and trim whitespace
        $this->excluded_categories = array_map('trim', $this->excluded_categories);
        $this->excluded_categories = array_filter($this->excluded_categories, function($value) {
            return $value !== '';
        });
        
        // Only log excluded categories when a sync is running
        $is_sync_running = get_transient('hempies_sync_running');
        if ($is_sync_running && !empty($this->excluded_categories)) {
            // Removed logging of excluded categories
        }
        
        $this->enable_emails = get_option('hempies_coa_enable_emails', false);
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Handle form submissions
        add_action('admin_init', array($this, 'handle_sync_actions'));
        
        // Register AJAX handlers
        add_action('wp_ajax_hempies_get_sync_status', array($this, 'ajax_get_sync_status'));
        
        // Register cron event for queue processing
        add_action('hempies_process_sync_queue', array($this, 'process_sync_queue'));
        
        // Register daily sync event
        add_action('hempies_daily_sync', array($this, 'run_daily_sync'));
        
        // Activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'plugin_activate'));
        register_deactivation_hook(__FILE__, array($this, 'plugin_deactivate'));
    }
    
    /**
     * Run daily automatic sync
     */
    public function run_daily_sync() {
        $this->log('Starting scheduled daily sync...');
        
        // Check if a sync is already running
        if (get_transient('hempies_sync_running')) {
            $this->log('Daily sync skipped: Another sync is already in progress');
            return;
        }
        
        // Start a full sync
        $this->start_sync(false);
    }
    
    /**
     * Plugin activation
     */
    public function plugin_activate() {
        // Schedule cron event for queue processing if not already scheduled
        if (!wp_next_scheduled('hempies_process_sync_queue')) {
            wp_schedule_event(time(), 'minute', 'hempies_process_sync_queue');
            $this->log('Scheduled recurring sync queue processing');
        }
        
        // Schedule daily sync if not already scheduled
        if (!wp_next_scheduled('hempies_daily_sync')) {
            // Schedule for 3 AM local time
            $timestamp = strtotime('tomorrow 3:00 am');
            wp_schedule_event($timestamp, 'daily', 'hempies_daily_sync');
            $this->log('Scheduled daily sync at 3 AM');
        }
        
        // Initialize transients
        set_transient('hempies_sync_running', false, 0);
        set_transient('hempies_sync_log', array(), 0);
        
        // Add plugin version to options
        update_option('hempies_coa_db_version', HEMPIES_COA_DB_VERSION);
        
        $this->log('Plugin activated. Version: ' . HEMPIES_COA_DB_VERSION);
    }
    
    /**
     * Plugin deactivation
     */
    public function plugin_deactivate() {
        // Remove scheduled cron events
        $timestamp = wp_next_scheduled('hempies_process_sync_queue');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'hempies_process_sync_queue');
        }
        
        $timestamp = wp_next_scheduled('hempies_daily_sync');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'hempies_daily_sync');
        }
        
        // Clear transients
        delete_transient('hempies_sync_running');
        delete_transient('hempies_sync_log');
        
        $this->log('Plugin deactivated');
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        add_menu_page(
            'COA Sync',
            'COA Sync',
            'manage_options',
            'hempies-coa-sync',
            array($this, 'render_admin_page'),
            'dashicons-update',
            30
        );
        
        add_submenu_page(
            'hempies-coa-sync',
            'COA Sync Settings',
            'Settings',
            'manage_options',
            'hempies-coa-settings',
            array($this, 'render_settings_page')
        );
        
        add_submenu_page(
            'hempies-coa-sync',
            'COA Sync Schedule',
            'Schedule',
            'manage_options',
            'hempies-coa-schedule',
            array($this, 'render_schedule_page')
        );
    }
    
    /**
     * Render schedule page
     */
    public function render_schedule_page() {
        ?>
        <div class="wrap">
            <h1>COA Sync Schedule</h1>
            
            <div class="card">
                <h2>Automatic Daily Sync</h2>
                <p>The plugin will automatically run a full sync once per day to check for new products and inventory changes.</p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Next Daily Sync</th>
                        <td>
                            <?php 
                            $timestamp = wp_next_scheduled('hempies_daily_sync');
                            if ($timestamp) {
                                echo date('Y-m-d H:i:s', $timestamp) . ' (' . human_time_diff($timestamp) . ' from now)';
                            } else {
                                echo 'Not scheduled';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
                
                <form method="post" action="">
                    <?php wp_nonce_field('hempies_sync', 'hempies_sync_nonce'); ?>
                    <button type="submit" name="sync_mode" value="reschedule_daily" class="button button-primary">
                        Reschedule Daily Sync
                    </button>
                    <p class="description">Reschedules the daily sync to occur at 3 AM tomorrow.</p>
                </form>
            </div>
            
            <div class="card">
                <h2>Manual Sync</h2>
                <p>You can also manually trigger a sync at any time from the main COA Sync page.</p>
                <a href="<?php echo admin_url('admin.php?page=hempies-coa-sync'); ?>" class="button button-secondary">
                    Go to Sync Page
                </a>
            </div>
        </div>
        
        <style type="text/css">
            .card {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                padding: 15px;
                margin-top: 20px;
                margin-bottom: 20px;
            }
        </style>
        <?php
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('hempies_coa_settings', 'hempies_coa_square_token');
        register_setting('hempies_coa_settings', 'hempies_coa_notification_email');
        register_setting('hempies_coa_settings', 'hempies_coa_excluded_categories', array(
            'sanitize_callback' => array($this, 'sanitize_excluded_categories')
        ));
        register_setting('hempies_coa_settings', 'hempies_coa_enable_emails', array(
            'default' => false
        ));
    }
    
    /**
     * Sanitize excluded categories
     */
    public function sanitize_excluded_categories($input) {
        // If input is a string (from textarea), convert to array
        if (is_string($input)) {
            // Split by newline, comma, or semicolon to allow flexible input
            $input = str_replace(array(',', ';'), "\n", $input);
            $categories = explode("\n", $input);
            $categories = array_map('trim', $categories);
            // Remove any empty entries
            $categories = array_filter($categories, function($value) {
                return $value !== '';
            });
            
            // Log the sanitized categories for debugging
            error_log("Sanitized excluded categories: " . implode(", ", $categories));
            
            return $categories;
        }
        
        // If it's already an array, return it
        if (is_array($input)) {
            // Ensure all elements are trimmed
            $input = array_map('trim', $input);
            // Remove any empty entries
            $input = array_filter($input, function($value) {
                return $value !== '';
            });
            
            // Log the array categories for debugging
            error_log("Array excluded categories: " . implode(", ", $input));
            return $input;
        }
        
        // Default to empty array
        error_log("Empty excluded categories array");
        return array();
    }
    
    /**
     * Handle sync form submissions
     */
    public function handle_sync_actions() {
        if (
            isset($_POST['sync_mode']) && 
            current_user_can('manage_options') && 
            check_admin_referer('hempies_sync', 'hempies_sync_nonce')
        ) {
            $sync_mode = sanitize_text_field($_POST['sync_mode']);
            
            $this->log('User initiated action: ' . $sync_mode);
            
            switch ($sync_mode) {
                case 'start_full':
                    $this->start_sync(false);
                    break;
                    
                case 'test_five':
                    $this->start_sync(true);
                    break;
                    
                case 'stop':
                    $this->stop_sync();
                    break;
                    
                case 'force_process':
                    $this->log('User manually triggered queue processing');
                    $this->process_sync_queue();
                    break;
                    
                case 'reset_cron':
                    $this->reset_cron();
                    break;
                    
                case 'reschedule_daily':
                    $this->reschedule_daily_sync();
                    break;
            }
            
            // Redirect back to the referring page
            $referer = wp_get_referer();
            if (!$referer) {
                $referer = admin_url('admin.php?page=hempies-coa-sync');
            }
            wp_safe_redirect($referer);
            exit;
        }
    }
    
    /**
     * Reschedule the daily sync job
     */
    private function reschedule_daily_sync() {
        // Clear existing scheduled events
        $timestamp = wp_next_scheduled('hempies_daily_sync');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'hempies_daily_sync');
            $this->log('Unscheduled existing daily sync');
        }
        
        // Schedule for 3 AM tomorrow
        $timestamp = strtotime('tomorrow 3:00 am');
        wp_schedule_event($timestamp, 'daily', 'hempies_daily_sync');
        $this->log('Rescheduled daily sync for ' . date('Y-m-d H:i:s', $timestamp));
    }
    
    /**
     * Reset cron job
     */
    private function reset_cron() {
        // Clear existing scheduled events
        $timestamp = wp_next_scheduled('hempies_process_sync_queue');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'hempies_process_sync_queue');
            $this->log('Unscheduled existing cron job');
        }
        
        // Schedule new event
        wp_schedule_event(time(), 'minute', 'hempies_process_sync_queue');
        $this->log('Scheduled new cron job for sync queue processing');
    }
    
    /**
     * Start a sync operation
     */
    private function start_sync($test_mode = false) {
        // Check if a sync is already running
        if (get_transient('hempies_sync_running')) {
            $this->log('Sync already in progress. Please wait for it to complete or stop it first.');
            return;
        }
        
        // Make sure we have an API token
        if (empty($this->square_access_token)) {
            $this->log('Error: Square API token is missing. Please configure it in Settings.');
            return;
        }
        
        // Reset log
        set_transient('hempies_sync_log', array(), 0);
        
        // Set sync as running
        set_transient('hempies_sync_running', true, 0);
        
        // Fetch SKUs from Square
        $skus = $this->fetch_square_items();
        
        if (empty($skus)) {
            $this->log('Error: No SKUs fetched from Square. Check API credentials and try again.');
            set_transient('hempies_sync_running', false, 0);
            return;
        }
        
        // If test mode, only use the first 5 SKUs
        if ($test_mode) {
            $skus = array_slice($skus, 0, 5);
            $this->log('Test mode: Limited to 5 SKUs');
        }
        
        // Save SKUs to queue
        update_option('hempies_sync_queue', $skus);
        update_option('hempies_sync_total', count($skus));
        update_option('hempies_sync_processed', 0);
        
        $this->log('Sync queue initialized with ' . count($skus) . ' items');
        
        // Process the first batch immediately instead of waiting for cron
        $this->process_sync_queue();
        
        // Make sure cron is scheduled
        if (!wp_next_scheduled('hempies_process_sync_queue')) {
            $this->log('Warning: Cron job was not scheduled. Scheduling it now.');
            wp_schedule_event(time(), 'minute', 'hempies_process_sync_queue');
        }
    }
    
    /**
     * Stop a running sync
     */
    private function stop_sync() {
        // Clear the queue
        update_option('hempies_sync_queue', array());
        update_option('hempies_sync_total', 0);
        update_option('hempies_sync_processed', 0);
        
        // Mark sync as not running
        set_transient('hempies_sync_running', false, 0);
        
        $this->log('Sync stopped by user');
    }
    
    /**
     * Process items in the sync queue
     */
    public function process_sync_queue() {
        // Check if a sync is running
        if (!get_transient('hempies_sync_running')) {
            $this->log('Warning: Attempted to process queue but sync is not running');
            return;
        }
        
        // Get queue
        $queue = get_option('hempies_sync_queue', array());
        
        // If queue is empty, mark sync as complete
        if (empty($queue)) {
            $this->log('Queue empty. Sync completed.');
            set_transient('hempies_sync_running', false, 0);
            return;
        }
        
        $this->log('Processing next batch from queue. Queue size: ' . count($queue));
        
        // Process next batch (up to 10 items)
        $batch = array_splice($queue, 0, 10);
        $batch_size = count($batch);
        
        $this->log("Processing batch of {$batch_size} items");
        
        foreach ($batch as $sku_data) {
            $this->process_sku($sku_data);
            
            // Update processed count
            $processed = get_option('hempies_sync_processed', 0);
            update_option('hempies_sync_processed', $processed + 1);
        }
        
        // Save updated queue
        update_option('hempies_sync_queue', $queue);
        
        // Calculate progress
        $total = get_option('hempies_sync_total', 0);
        $processed = get_option('hempies_sync_processed', 0);
        
        if ($total > 0) {
            $percentage = round(($processed / $total) * 100);
            $this->log("Processed batch of {$batch_size} items. Progress: {$processed}/{$total} ({$percentage}%)");
        }
        
        // If queue is now empty, mark sync as complete
        if (empty($queue)) {
            $this->log('All items processed. Sync completed.');
            set_transient('hempies_sync_running', false, 0);
        } else {
            // Schedule immediate processing of next batch if we have more than 20 items in queue
            if (count($queue) > 20) {
                $this->log('Large queue detected. Scheduling immediate processing of next batch.');
                wp_schedule_single_event(time() + 5, 'hempies_process_sync_queue');
            }
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
        
        // Create a full product name by combining parent name and variation name
        $full_product_name = $parent_name;
        if (!empty($name) && $name !== $parent_name) {
            $full_product_name .= ' - ' . $name;
        }
        
        // Log the SKU being processed
        $this->log("Processing SKU: {$sku} - {$full_product_name} (Qty: {$quantity}, Archived: " . ($is_archived ? 'Yes' : 'No') . ")");
        
        // Skip if archived
        if ($is_archived) {
            $this->log("Skipped: {$sku} - Item is archived in Square");
            
            // If product exists, set to draft
            $product_id = $this->get_product_by_sku($sku);
            if ($product_id) {
                $this->update_product_status($product_id, 'draft', false);
                $this->log("Updated: {$sku} - Set to draft (archived)");
            }
            
            return;
        }
        
        // Skip if any category is in excluded list
        $should_skip = false;
        
        // Log the categories being checked
        $this->log("Checking categories for SKU {$sku}:");
        if (!empty($category_ids)) {
            $this->log("Category IDs: " . implode(", ", $category_ids));
        }
        if (!empty($category_names)) {
            $this->log("Category Names: " . implode(", ", $category_names));
        }
        
        // Normalize the excluded categories array
        $normalized_excluded = array();
        foreach ($this->excluded_categories as $excluded) {
            $normalized_excluded[] = trim(strtolower($excluded));
        }
        $this->log("Normalized excluded categories: " . implode(", ", $normalized_excluded));
        
        // Check if we have category_ids array (multi-category support)
        if (!empty($category_ids) && is_array($category_ids)) {
            foreach ($category_ids as $index => $cat_id) {
                $cat_name = isset($category_names[$index]) ? $category_names[$index] : '';
                
                // Debug the current category being checked
                $this->log("Checking category for SKU {$sku}: ID: {$cat_id}, Name: {$cat_name}");
                
                // Check by ID (case-insensitive)
                $normalized_cat_id = trim(strtolower($cat_id));
                if (in_array($normalized_cat_id, $normalized_excluded)) {
                    $this->log("Skipped: {$sku} - In excluded category ID: {$cat_id} (Name: {$cat_name})");
                    return; // Skip this item completely
                }
                
                // Check by name (case-insensitive)
                if (!empty($cat_name)) {
                    $normalized_cat_name = trim(strtolower($cat_name));
                    if (in_array($normalized_cat_name, $normalized_excluded)) {
                        $this->log("Skipped: {$sku} - In excluded category Name: {$cat_name} (ID: {$cat_id})");
                        return; // Skip this item completely
                    }
                }
            }
        } 
        // Fallback to check single category (old format)
        else if (!empty($category)) {
            // Debug the current category being checked
            $this->log("Checking single category for SKU {$sku}: ID: {$category}, Name: {$category_name}");
            
            // Check by ID (case-insensitive)
            $normalized_category = trim(strtolower($category));
            if (in_array($normalized_category, $normalized_excluded)) {
                $this->log("Skipped: {$sku} - In excluded category ID: {$category} (Name: {$category_name})");
                return; // Skip this item completely
            }
            
            // Check by name (case-insensitive)
            if (!empty($category_name)) {
                $normalized_category_name = trim(strtolower($category_name));
                if (in_array($normalized_category_name, $normalized_excluded)) {
                    $this->log("Skipped: {$sku} - In excluded category Name: {$category_name} (ID: {$category})");
                    return; // Skip this item completely
                }
            }
        }
        
        // Skip if quantity is 0 or less
        if ($quantity <= 0) {
            $this->log("Skipped: {$sku} - Out of stock (Qty: {$quantity})");
            
            // If product exists, set to draft
            $product_id = $this->get_product_by_sku($sku);
            if ($product_id) {
                $this->update_product_status($product_id, 'draft', false);
                $this->log("Updated: {$sku} - Set to draft (out of stock)");
            }
            
            return;
        }
        
        // Check if product already exists
        $product_id = $this->get_product_by_sku($sku);
        
        if ($product_id) {
            // Product exists, update it
            $this->log("Updating existing product ID {$product_id} for SKU: {$sku}");
            $this->update_product_title_and_status($product_id, $full_product_name, 'pending', true);
            $this->log("Updated: {$sku} - Set to pending and showing COA (in stock)");
            
            // Send notification if product was previously draft (out of stock)
            $post_status = get_post_status($product_id);
            if ($post_status === 'draft') {
                $this->send_notification_email($sku, $full_product_name);
            }
        } else {
            // Product doesn't exist, create it with full name
            $this->log("Creating new product for SKU: {$sku} with name: {$full_product_name}");
            $new_product_id = $this->create_product($sku, $full_product_name, $sku_data);
            if ($new_product_id) {
                $this->log("Created: {$sku} - New product created with ID: {$new_product_id}");
                $this->send_notification_email($sku, $full_product_name);
            } else {
                $this->log("Error: Failed to create product for SKU: {$sku}");
            }
        }
    }
    
    /**
     * Get product ID by SKU
     */
    private function get_product_by_sku($sku) {
        global $wpdb;
        
        // First check the standard _sku meta field
        $product_id = $wpdb->get_var($wpdb->prepare("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_sku'
            AND meta_value = %s
            LIMIT 1
        ", $sku));
        
        // If not found, also check the ACF field (if used)
        if (!$product_id && function_exists('get_field_objects')) {
            $product_id = $wpdb->get_var($wpdb->prepare("
                SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = 'sku'
                AND meta_value = %s
                LIMIT 1
            ", $sku));
        }
        
        // Log the lookup result for debugging
        if ($product_id) {
            $this->log("Found existing product with ID {$product_id} for SKU: {$sku}");
        } else {
            $this->log("No existing product found for SKU: {$sku}");
        }
        
        return $product_id;
    }
    
    /**
     * Update product title, status and COA visibility
     */
    private function update_product_title_and_status($product_id, $title, $status, $show_coa) {
        // Update post status and title
        wp_update_post(array(
            'ID' => $product_id,
            'post_title' => $title,
            'post_status' => $status
        ));
        
        // Get the SKU
        $sku = get_post_meta($product_id, '_sku', true);
        
        // Update COA visibility meta
        update_post_meta($product_id, '_show_coa', $show_coa ? 'yes' : 'no');
        
        // For ACF fields, use direct post meta instead of update_field() function
        // This avoids the field key error where 'field_67e8aba617a66' is stored instead of the actual SKU value
        update_post_meta($product_id, 'sku', $sku);
        
        // Log debug information
        $this->log("Updated product {$product_id} with SKU: {$sku}, Title: {$title}, Status: {$status}");
    }

    /**
     * Update product status and COA visibility
     */
    private function update_product_status($product_id, $status, $show_coa) {
        // Update post status
        wp_update_post(array(
            'ID' => $product_id,
            'post_status' => $status
        ));
        
        // Update COA visibility meta
        update_post_meta($product_id, '_show_coa', $show_coa ? 'yes' : 'no');
        
        // Make sure SKU is also in ACF field
        $sku = get_post_meta($product_id, '_sku', true);
        
        // For ACF fields, use direct post meta instead of update_field() function
        // This avoids the field key error where 'field_67e8aba617a66' is stored instead of the actual SKU value
        update_post_meta($product_id, 'sku', $sku);
        
        // Log debug information
        $this->log("Updated product status for {$product_id} with SKU: {$sku}, Status: {$status}");
    }
    
    /**
     * Create a new product
     */
    private function create_product($sku, $name, $data) {
        // Create post object
        $product = array(
            'post_title'   => $name,
            'post_content' => '',
            'post_status'  => 'pending',
            'post_type'    => 'product'
        );
        
        // Insert the post into the database
        $product_id = wp_insert_post($product);
        
        if (!$product_id) {
            return false;
        }
        
        // Set product meta
        update_post_meta($product_id, '_sku', $sku);
        update_post_meta($product_id, '_show_coa', 'yes');
        
        // For ACF fields, use direct post meta instead of update_field() function
        // This avoids the field key error where 'field_67e8aba617a66' is stored instead of the actual SKU value
        update_post_meta($product_id, 'sku', $sku);
        
        // Log debug information
        $this->log("Created product {$product_id} with SKU: {$sku}, Name: {$name}");
        
        return $product_id;
    }
    
    /**
     * Send notification email for items that need COA review
     */
    private function send_notification_email($sku, $name) {
        // Check if emails are enabled
        if (!get_option('hempies_coa_enable_emails', false)) {
            $this->log("Email notification skipped (disabled in settings) for SKU: {$sku}");
            return;
        }
        
        $subject = 'Product requires COA review: ' . $sku;
        
        $message = "Hello,\n\n";
        $message .= "The following product is now in stock and requires a COA review:\n\n";
        $message .= "SKU: {$sku}\n";
        $message .= "Name: {$name}\n\n";
        $message .= "Please review and approve the COA for this product.\n\n";
        $message .= "This is an automated message from the Hempie's COA DB Sync plugin.\n";
        
        wp_mail($this->notification_email, $subject, $message);
        
        $this->log("Notification email sent for SKU: {$sku}");
    }

    /**
     * Fetch items from Square API with pagination support
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
            
            // Make API request
            $response = wp_remote_get($endpoint, array(
                'headers' => $headers,
                'timeout' => 60
            ));
            
            // Check for errors
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $this->log("Error fetching from Square API: {$error_message}");
                break;
            }
            
            // Parse response
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (!isset($data['objects']) || empty($data['objects'])) {
                break;
            }
            
            // Process items
            foreach ($data['objects'] as $item) {
                // Skip items without categories
                if (empty($item['item_data']['category_id'])) {
                    $this->log("Skipping item {$item['id']} - No category assigned");
                    continue;
                }
                
                // Skip items in excluded categories
                if (in_array($item['item_data']['category_id'], $this->excluded_categories) || 
                    in_array($item['item_data']['category_data']['name'], $this->excluded_categories)) {
                    $this->log("Skipping item {$item['id']} - Category excluded");
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
                                'variation_name' => $variation['item_variation_data']['name'] ?? '',
                                'category_id' => $item['item_data']['category_id']
                            );
                        }
                    }
                }
            }
            
            // Check if there are more items
            $has_more = isset($data['cursor']) && !empty($data['cursor']);
            $cursor = $has_more ? $data['cursor'] : null;
        }
        
        return $items;
    }
    
    /**
     * Get inventory data for multiple items at once
     */
    private function get_bulk_inventory($variation_ids) {
        if (empty($variation_ids)) {
            return array();
        }
        
        // Square API endpoint for bulk inventory
        $endpoint = 'https://connect.squareup.com/v2/inventory/batch-retrieve-counts';
        
        // API request headers
        $headers = array(
            'Square-Version' => '2023-09-25',
            'Authorization' => 'Bearer ' . $this->square_access_token,
            'Content-Type' => 'application/json'
        );
        
        // Create request body
        $body = array(
            'catalog_object_ids' => $variation_ids
        );
        
        // Make API request
        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 60
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log("Warning: Could not fetch inventory batch: {$error_message}");
            return array();
        }
        
        // Check HTTP response code
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            $this->log("Warning: Inventory API returned status {$http_code}");
            return array();
        }
        
        // Parse response
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $inventory = array();
        if (isset($data['counts']) && !empty($data['counts'])) {
            foreach ($data['counts'] as $count) {
                if (isset($count['catalog_object_id']) && isset($count['quantity'])) {
                    $variation_id = $count['catalog_object_id'];
                    $quantity = intval($count['quantity']);
                    
                    // If we already have inventory for this variation, add to it
                    if (isset($inventory[$variation_id])) {
                        $inventory[$variation_id] += $quantity;
                    } else {
                        $inventory[$variation_id] = $quantity;
                    }
                    
                    // Log inventory data for debugging
                    $this->log("Inventory for variation {$variation_id} at location {$count['location_id']}: {$quantity}");
                }
            }
            
            // Log total inventory for each variation
            foreach ($inventory as $variation_id => $total_quantity) {
                $this->log("Total inventory for variation {$variation_id} across all locations: {$total_quantity}");
            }
        } else {
            $this->log("Warning: No inventory counts found in response");
            $this->log("Response data: " . print_r($data, true));
        }
        
        return $inventory;
    }
    
    public function fetch_square_categories() {
        $this->log('Fetching categories from Square API...');
        
        // Initialize categories array
        $categories = array();
        
        // Square API endpoint for categories
        $endpoint = 'https://connect.squareup.com/v2/catalog/list?types=CATEGORY';
        
        // API request headers
        $headers = array(
            'Square-Version' => '2023-09-25',
            'Authorization' => 'Bearer ' . $this->square_access_token,
            'Content-Type' => 'application/json'
        );
        
        // Variables for pagination
        $cursor = null;
        $page_count = 0;
        $has_more_results = true;
        
        // Loop through pages until we have all categories
        while ($has_more_results) {
            $page_count++;
            
            // Add cursor parameter if we have one from previous request
            $request_endpoint = $endpoint;
            if ($cursor) {
                $request_endpoint .= '&cursor=' . urlencode($cursor);
            }
            
            // Make API request
            $response = wp_remote_get($request_endpoint, array(
                'headers' => $headers,
                'timeout' => 30
            ));
            
            // Check for errors
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $this->log("Error fetching categories from Square API: {$error_message}");
                break;
            }
            
            // Parse response
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (!isset($data['objects']) || empty($data['objects'])) {
                if ($page_count === 1) {
                    $this->log('No categories found in Square catalog or API error');
                } else {
                    $this->log('No more categories found');
                }
                break;
            }
            
            $page_items_count = count($data['objects']);
            $this->log("Found {$page_items_count} category objects on page {$page_count}");
            
            // Process category objects
            foreach ($data['objects'] as $object) {
                if ($object['type'] === 'CATEGORY' && isset($object['id']) && isset($object['category_data']['name'])) {
                    $categories[$object['id']] = $object['category_data']['name'];
                }
            }
            
            // Check if there are more pages
            if (isset($data['cursor']) && !empty($data['cursor'])) {
                $cursor = $data['cursor'];
                $this->log("Found cursor for next category page: {$cursor}");
            } else {
                $has_more_results = false;
                $this->log("No more category pages available");
            }
            
            // Add a small delay between requests to avoid rate limiting
            if ($has_more_results) {
                sleep(1);
            }
        }
        
        $this->log('Fetched ' . count($categories) . ' categories from Square');
        
        // Debug complete category list for troubleshooting
        if (count($categories) > 0) {
            $this->log('All fetched categories:');
            foreach ($categories as $id => $name) {
                $this->log("Category: ID: {$id}, Name: {$name}");
            }
        }
        
        return $categories;
    }
    
    /**
     * Get inventory data for an item
     */
    private function get_item_inventory($variation_id) {
        // Square API endpoint for inventory
        $endpoint = 'https://connect.squareup.com/v2/inventory/' . $variation_id;
        
        // API request headers
        $headers = array(
            'Square-Version' => '2023-09-25',
            'Authorization' => 'Bearer ' . $this->square_access_token,
            'Content-Type' => 'application/json'
        );
        
        // Make API request
        $response = wp_remote_get($endpoint, array(
            'headers' => $headers,
            'timeout' => 30
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log("Warning: Could not fetch inventory for item {$variation_id}: {$error_message}");
            return 0;
        }
        
        // Check HTTP response code
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            $this->log("Warning: Inventory API returned status {$http_code} for item {$variation_id}");
            return 0;
        }
        
        // Parse response
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Extract quantity
        $quantity = 0;
        if (isset($data['counts']) && !empty($data['counts'])) {
            $quantity = isset($data['counts'][0]['quantity']) ? intval($data['counts'][0]['quantity']) : 0;
        } else {
            $this->log("Warning: No inventory counts found for item {$variation_id}");
        }
        
        return $quantity;
    }
}

// Initialize COA Dashboard
add_action('plugins_loaded', function() {
    Hempies_COA_Dashboard::get_instance();
});