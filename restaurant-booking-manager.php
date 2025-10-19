<?php
/**
 * Plugin Name: Restaurant Booking Manager
 * Plugin URI: https://github.com/newit5s/wp_booking-table
 * Description: Plugin quản lý đặt bàn nhà hàng hoàn chỉnh với giao diện thân thiện
 * Version: 1.0.1
 * Author: NewIT5S
 * Author URI: https://github.com/newit5s
 * License: GPL v2 or later
 * Text Domain: restaurant-booking
 * Domain Path: /languages
 */

// Ngăn truy cập trực tiếp
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('RB_VERSION', '1.0.1');
define('RB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RB_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Activation Hook - Tạo database tables
 */
register_activation_hook(__FILE__, 'rb_activate_plugin');
function rb_activate_plugin() {
    require_once RB_PLUGIN_DIR . 'includes/class-database.php';
    require_once RB_PLUGIN_DIR . 'includes/class-location.php';
    $database = new RB_Database();
    $database->create_tables();

    add_option('rb_settings', array(
        'max_tables' => 20,
        'opening_time' => '09:00',
        'closing_time' => '22:00',
        'time_slot_interval' => 30,
        'admin_email' => get_option('admin_email'),
        'enable_email' => 'yes'
    ));

    flush_rewrite_rules();
}

/**
 * Deactivation Hook
 */
register_deactivation_hook(__FILE__, 'rb_deactivate_plugin');
function rb_deactivate_plugin() {
    flush_rewrite_rules();
}

/**
 * Load plugin textdomain
 */
add_action('plugins_loaded', 'rb_load_textdomain');
function rb_load_textdomain() {
    load_plugin_textdomain('restaurant-booking', false, dirname(RB_PLUGIN_BASENAME) . '/languages');
}

/**
 * Initialize Plugin
 */
add_action('plugins_loaded', 'rb_init_plugin', 5);
function rb_init_plugin() {
    // Load I18n FIRST
    require_once RB_PLUGIN_DIR . 'includes/class-i18n.php';
    RB_I18n::get_instance();
    
    // Load Language Switcher
    require_once RB_PLUGIN_DIR . 'includes/class-language-switcher.php';
    new RB_Language_Switcher();
    
    // Load required files
    require_once RB_PLUGIN_DIR . 'includes/class-database.php';
    require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
    require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
    require_once RB_PLUGIN_DIR . 'includes/class-ajax.php';
    require_once RB_PLUGIN_DIR . 'includes/class-email.php';
    require_once RB_PLUGIN_DIR . 'includes/class-location.php';
    require_once RB_PLUGIN_DIR . 'includes/class-portal-account.php';
    require_once RB_PLUGIN_DIR . 'includes/class-assets-manager.php';

    // Initialize globals
    global $rb_database, $rb_booking, $rb_customer, $rb_email, $rb_location;
    $rb_database = new RB_Database();
    $rb_database->ensure_portal_schema();
    $rb_booking = new RB_Booking();
    $rb_customer = new RB_Customer();
    $rb_email = new RB_Email();
    $rb_location = new RB_Location();
    
    // Initialize AJAX
    new RB_Ajax();
    
    // Load Admin
    if (is_admin()) {
        require_once RB_PLUGIN_DIR . 'admin/class-admin.php';
        new RB_Admin();
    }
    
    // Load Frontend
    if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
        if (!class_exists('RB_Frontend')) {
            require_once RB_PLUGIN_DIR . 'public/class-frontend.php';
        }
        new RB_Frontend();
    }
}

add_action('init', 'rb_disable_wp_location_manager_role');

/**
 * Remove legacy WordPress-based location manager role/capabilities.
 */
function rb_disable_wp_location_manager_role() {
    remove_role('rb_location_manager');

    if ($administrator = get_role('administrator')) {
        $administrator->remove_cap('rb_manage_location');
    }
}

/**
 * Enqueue admin scripts and styles
 */
add_action('admin_enqueue_scripts', 'rb_admin_enqueue_scripts');
function rb_admin_enqueue_scripts($hook) {
    if (strpos($hook, 'restaurant-booking') !== false || strpos($hook, 'rb-') !== false) {
        wp_enqueue_style('rb-admin-css', RB_PLUGIN_URL . 'assets/css/admin.css', array(), RB_VERSION);
        wp_enqueue_script('rb-admin-js', RB_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), RB_VERSION, true);
        
        wp_localize_script('rb-admin-js', 'rb_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rb_admin_nonce'),
            'language_nonce' => wp_create_nonce('rb_language_nonce'),
            'translations' => RB_I18n::get_instance()->get_js_translations(),
            'current_language' => rb_get_current_language()
        ));
    }
}

/**
 * Enqueue frontend scripts and styles
 */
add_action('wp_enqueue_scripts', 'rb_frontend_enqueue_scripts');
function rb_frontend_enqueue_scripts() {
    if (is_admin()) {
        return;
    }

    $should_enqueue = apply_filters('rb_enqueue_legacy_frontend_assets', false);

    if (!$should_enqueue) {
        $legacy_shortcodes = array('restaurant_booking_manager');

        if (is_singular()) {
            global $post;
            if ($post && isset($post->post_content)) {
                foreach ($legacy_shortcodes as $shortcode) {
                    if (has_shortcode($post->post_content, $shortcode)) {
                        $should_enqueue = true;
                        break;
                    }
                }
            }
        }

        if (!$should_enqueue && (is_front_page() || is_home())) {
            $queried_id = get_queried_object_id();
            if ($queried_id) {
                $content = get_post_field('post_content', $queried_id);
                if ($content) {
                    foreach ($legacy_shortcodes as $shortcode) {
                        if (has_shortcode($content, $shortcode)) {
                            $should_enqueue = true;
                            break;
                        }
                    }
                }
            }
        }
    }

    if (!$should_enqueue) {
        return;
    }

    $style_path = RB_PLUGIN_DIR . 'assets/css/frontend.css';
    $script_path = RB_PLUGIN_DIR . 'assets/js/frontend.js';

    $style_version = file_exists($style_path) ? filemtime($style_path) : RB_VERSION;
    $script_version = file_exists($script_path) ? filemtime($script_path) : RB_VERSION;

    wp_enqueue_style('rb-frontend-css', RB_PLUGIN_URL . 'assets/css/frontend.css', array(), $style_version);
    wp_enqueue_script('rb-frontend-js', RB_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), $script_version, true);

    $gmail_style_path = RB_PLUGIN_DIR . 'assets/css/manager-gmail-style.css';
    $gmail_script_path = RB_PLUGIN_DIR . 'assets/js/manager-gmail.js';

    if (file_exists($gmail_style_path)) {
        $gmail_style_version = filemtime($gmail_style_path);
        wp_enqueue_style('rb-manager-gmail', RB_PLUGIN_URL . 'assets/css/manager-gmail-style.css', array('rb-frontend-css'), $gmail_style_version);
    }

    if (file_exists($gmail_script_path)) {
        $gmail_script_version = filemtime($gmail_script_path);
        wp_enqueue_script('rb-manager-gmail', RB_PLUGIN_URL . 'assets/js/manager-gmail.js', array('jquery', 'rb-frontend-js'), $gmail_script_version, true);
    }

    global $rb_location;
    if (!$rb_location) {
        require_once RB_PLUGIN_DIR . 'includes/class-location.php';
        $rb_location = new RB_Location();
    }

    $location_data = array();
    if ($rb_location) {
        $locations = $rb_location->all();
        foreach ($locations as $location) {
            $location_data[] = array(
                'id' => (int) $location->id,
                'name' => $location->name,
                'slug' => $location->slug,
                'hotline' => $location->hotline,
                'email' => $location->email,
                'address' => $location->address,
                'opening_time' => $location->opening_time,
                'closing_time' => $location->closing_time,
                'time_slot_interval' => (int) $location->time_slot_interval,
                'min_advance_booking' => (int) $location->min_advance_booking,
                'max_advance_booking' => (int) $location->max_advance_booking,
                'languages' => array_map('trim', explode(',', $location->languages)),
            );
        }
    }

    $default_location_id = !empty($location_data) ? (int) $location_data[0]['id'] : 0;

    wp_localize_script('rb-frontend-js', 'rb_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('rb_frontend_nonce'),
        'language_nonce' => wp_create_nonce('rb_language_nonce'),
        'translations' => RB_I18n::get_instance()->get_js_translations(),
        'current_language' => rb_get_current_language(),
        'locations' => $location_data,
        'default_location_id' => $default_location_id,
        'loading_text' => rb_t('checking', __('Checking...', 'restaurant-booking')),
        'error_text' => rb_t('error_generic', __('Something went wrong. Please try again.', 'restaurant-booking')),
        'invalid_phone_text' => rb_t('invalid_phone', __('Invalid phone number. Please enter a valid phone number.', 'restaurant-booking')),
        'choose_location_text' => rb_t('choose_location', __('Please select a location.', 'restaurant-booking')),
        'choose_language_text' => rb_t('choose_language', __('Please select a language.', 'restaurant-booking')),
        'missing_fields_text' => rb_t('availability_missing_fields', __('Please select date, time, guests and location before checking availability.', 'restaurant-booking')),
        'select_time_text' => rb_t('select_time', __('Select Time', 'restaurant-booking')),
        'no_slots_text' => rb_t('no_slots_available', __('No available times', 'restaurant-booking')),
        'confirm_text' => __('Confirm', 'restaurant-booking'),
        'cancel_text' => __('Cancel', 'restaurant-booking'),
        'complete_text' => __('Complete', 'restaurant-booking'),
        'no_actions_text' => __('No actions available', 'restaurant-booking'),
        'no_history_text' => __('No history found.', 'restaurant-booking'),
        'confirm_delete_table' => __('Are you sure you want to delete this table?', 'restaurant-booking'),
        'confirm_set_vip' => __('Upgrade this customer to VIP?', 'restaurant-booking'),
        'confirm_blacklist' => __('Blacklist this customer?', 'restaurant-booking'),
        'confirm_unblacklist' => __('Remove this customer from blacklist?', 'restaurant-booking'),
        'bulk_cancel_confirm' => __('Cancel selected bookings?', 'restaurant-booking'),
        'booking_actions_label' => rb_t('booking_actions', __('Booking actions', 'restaurant-booking')),
        'customer_actions_label' => rb_t('customer_actions', __('Customer actions', 'restaurant-booking'))
    ));
}

/**
 * Register shortcode
 */
add_shortcode('restaurant_booking', 'rb_booking_shortcode');
add_shortcode('restaurant_booking_portal', 'rb_booking_shortcode');
function rb_booking_shortcode($atts) {
    if (!class_exists('RB_Frontend')) {
        require_once RB_PLUGIN_DIR . 'public/class-frontend.php';
    }

    $frontend = new RB_Frontend();
    return $frontend->render_booking_form($atts);
}

add_shortcode('restaurant_booking_manager', 'rb_booking_manager_shortcode');
function rb_booking_manager_shortcode($atts) {
    if (!class_exists('RB_Frontend')) {
        require_once RB_PLUGIN_DIR . 'public/class-frontend.php';
    }

    $frontend = new RB_Frontend();
    return $frontend->render_location_manager($atts);
}

/**
 * Add plugin action links
 */
add_filter('plugin_action_links_' . RB_PLUGIN_BASENAME, 'rb_plugin_action_links');
function rb_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=rb-settings') . '">' . rb_t('settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

/**
 * Check plugin dependencies
 */
add_action('admin_notices', 'rb_check_dependencies');
function rb_check_dependencies() {
    if (version_compare(PHP_VERSION, '7.0', '<')) {
        ?>
        <div class="notice notice-error">
            <p><?php echo rb_t('php_version_error'); ?></p>
        </div>
        <?php
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'rb_bookings';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php echo rb_t('database_tables_error'); ?></p>
        </div>
        <?php
    }
}
