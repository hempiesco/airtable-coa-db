<?php
/**
 * COA Dashboard Class
 * 
 * Handles the COA management dashboard functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class Hempies_COA_Dashboard {
    private static $instance = null;
    private $coa_expiration_days = 30; // Configurable expiration warning period

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_dashboard_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_dashboard_scripts'));
        add_action('wp_ajax_hempies_coa_upload', array($this, 'handle_coa_upload'));
        add_action('wp_ajax_hempies_coa_update_status', array($this, 'handle_status_update'));
        add_action('wp_ajax_hempies_coa_get_products', array($this, 'get_products_data'));
        add_action('wp_ajax_hempies_coa_exclude_product', array($this, 'handle_exclude_product'));
    }

    public function add_dashboard_menu() {
        add_submenu_page(
            'hempies-coa-sync',
            'COA Management',
            'COA Management',
            'manage_options',
            'hempies-coa-management',
            array($this, 'render_dashboard')
        );
    }

    public function enqueue_dashboard_scripts($hook) {
        if ('hempies-coa-sync_page_hempies-coa-management' !== $hook) {
            return;
        }

        // Enqueue styles
        wp_enqueue_style('hempies-coa-dashboard', plugins_url('assets/css/dashboard.css', __FILE__));
        
        // Enqueue scripts
        wp_enqueue_script('hempies-coa-dashboard', plugins_url('assets/js/dashboard.js', __FILE__), array('jquery'), '1.0.0', true);
        
        // Localize script
        wp_localize_script('hempies-coa-dashboard', 'hempiesCoaData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hempies_coa_nonce')
        ));
    }

    public function render_dashboard() {
        ?>
        <div class="wrap hempies-coa-dashboard">
            <h1>COA Management</h1>
            
            <!-- Search and Filter -->
            <div class="coa-filters">
                <div class="search-box">
                    <input type="text" id="coa-search" placeholder="Search products...">
                </div>
                <div class="filter-box">
                    <select id="coa-status-filter">
                        <option value="">All Statuses</option>
                        <option value="needs_coa">Needs COA</option>
                        <option value="expiring">Expiring</option>
                        <option value="expired">Expired</option>
                        <option value="published">Published</option>
                        <option value="pending">Pending Review</option>
                    </select>
                </div>
            </div>

            <!-- Products Table -->
            <div class="coa-products-table">
                <table id="coa-products">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Product Name</th>
                            <th>COA Status</th>
                            <th>Expiration Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be loaded via AJAX -->
                    </tbody>
                </table>
            </div>

            <!-- COA Upload Modal -->
            <div id="coa-upload-modal" class="modal">
                <div class="modal-content">
                    <span class="close">&times;</span>
                    <h2>Upload COA</h2>
                    <div class="upload-area" id="coa-drop-zone">
                        <p>Drag and drop COA file here or click to browse</p>
                        <input type="file" id="coa-file-input" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                    <div class="coa-preview" id="coa-preview"></div>
                    <div class="coa-details">
                        <input type="date" id="coa-expiration-date" placeholder="Expiration Date">
                        <button id="save-coa" class="button button-primary">Save COA</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function get_products_data() {
        check_ajax_referer('hempies_coa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        // Query products from Kadence
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'sku',
                    'compare' => 'EXISTS'
                ),
                array(
                    'key' => '_excluded_from_coa',
                    'compare' => 'NOT EXISTS'
                )
            )
        );

        $products = get_posts($args);
        $data = array();

        foreach ($products as $product) {
            // Get product meta
            $sku = get_post_meta($product->ID, 'sku', true);
            $coa_document = get_post_meta($product->ID, 'coa_document', true);
            $coa_creation_date = get_post_meta($product->ID, 'coa_creation_date', true);
            $show_coa = get_post_meta($product->ID, 'show_coa', true);
            
            // Determine COA status
            $coa_status = 'needs_coa';
            if ($coa_document && $show_coa === 'yes') {
                $coa_status = 'published';
            } else if ($coa_document && $show_coa !== 'yes') {
                $coa_status = 'pending';
            }

            $data[] = array(
                'id' => $product->ID,
                'sku' => $sku ?: 'N/A',
                'name' => $product->post_title,
                'status' => $coa_status,
                'creation_date' => $coa_creation_date ?: 'N/A',
                'actions' => array(
                    'edit' => get_edit_post_link($product->ID),
                    'view' => $coa_document ?: '#'
                )
            );
        }

        wp_send_json_success(array(
            'products' => $data
        ));
    }

    public function handle_coa_upload() {
        check_ajax_referer('hempies_coa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $product_id = intval($_POST['product_id']);
        $expiration_date = sanitize_text_field($_POST['expiration_date']);

        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $uploadedfile = $_FILES['coa_file'];
        $upload_overrides = array('test_form' => false);
        $movefile = wp_handle_upload($uploadedfile, $upload_overrides);

        if ($movefile && !isset($movefile['error'])) {
            // Save COA file URL
            update_post_meta($product_id, '_coa_file', $movefile['url']);
            update_post_meta($product_id, '_coa_expiration', $expiration_date);
            update_post_meta($product_id, '_coa_status', 'pending');

            // Log the upload
            $this->log_coa_activity($product_id, 'upload', get_current_user_id());

            wp_send_json_success('COA uploaded successfully');
        } else {
            wp_send_json_error($movefile['error']);
        }
    }

    public function handle_status_update() {
        check_ajax_referer('hempies_coa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $product_id = intval($_POST['product_id']);
        $status = sanitize_text_field($_POST['status']);

        update_post_meta($product_id, '_coa_status', $status);
        
        // Log the status change
        $this->log_coa_activity($product_id, 'status_change', get_current_user_id(), $status);

        wp_send_json_success('Status updated successfully');
    }

    public function handle_exclude_product() {
        check_ajax_referer('hempies_coa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $product_id = intval($_POST['product_id']);
        
        // Mark product as excluded
        update_post_meta($product_id, '_excluded_from_coa', true);
        
        // Hide product from frontend
        wp_update_post(array(
            'ID' => $product_id,
            'post_status' => 'private'
        ));
        
        // Log the exclusion
        $this->log_coa_activity($product_id, 'exclude', get_current_user_id());

        wp_send_json_success('Product excluded successfully');
    }

    private function log_coa_activity($product_id, $action, $user_id, $details = '') {
        $log = get_post_meta($product_id, '_coa_activity_log', true);
        if (!is_array($log)) {
            $log = array();
        }

        $log[] = array(
            'timestamp' => current_time('mysql'),
            'user_id' => $user_id,
            'action' => $action,
            'details' => $details
        );

        update_post_meta($product_id, '_coa_activity_log', $log);
    }
} 