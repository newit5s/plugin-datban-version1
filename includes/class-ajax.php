<?php
/**
 * AJAX Class - Xử lý AJAX requests
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Ajax {
    
    public function __construct() {
        // Admin AJAX handlers
        add_action('wp_ajax_rb_admin_confirm_booking', array($this, 'admin_confirm_booking'));
        add_action('wp_ajax_rb_admin_cancel_booking', array($this, 'admin_cancel_booking'));
        add_action('wp_ajax_rb_admin_complete_booking', array($this, 'admin_complete_booking'));
        add_action('wp_ajax_rb_admin_delete_booking', array($this, 'admin_delete_booking'));
        add_action('wp_ajax_rb_admin_toggle_table', array($this, 'admin_toggle_table'));
        add_action('wp_ajax_rb_admin_add_table', array($this, 'admin_add_table'));
        add_action('wp_ajax_rb_admin_delete_table', array($this, 'admin_delete_table'));
        add_action('wp_ajax_rb_get_customer_history', array($this, 'get_customer_history'));
        add_action('wp_ajax_rb_set_customer_vip', array($this, 'set_customer_vip'));
        add_action('wp_ajax_rb_set_customer_blacklist', array($this, 'set_customer_blacklist'));
        add_action('wp_ajax_rb_get_customer_stats', array($this, 'get_customer_stats'));
        add_action('wp_ajax_rb_update_customer_note', array($this, 'update_customer_note'));
        
        // NEW: Advanced features from Settings
        add_action('wp_ajax_rb_cleanup_old_bookings', array($this, 'cleanup_old_bookings'));
        add_action('wp_ajax_rb_reset_plugin', array($this, 'reset_plugin'));
    }
    
    
    public function admin_confirm_booking() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'restaurant-booking'));
        }
        
        if (!check_ajax_referer('rb_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }
        
        $booking_id = intval($_POST['booking_id']);
        
        global $rb_booking;
        $result = $rb_booking->confirm_booking($booking_id);
        
        if (!is_wp_error($result)) {
            // Send confirmation email
            $booking = $rb_booking->get_booking($booking_id);
            if ($booking && class_exists('RB_Email')) {
                $email = new RB_Email();
                $email->send_confirmation_email($booking);
            }
            
            wp_send_json_success(array('message' => __('Booking confirmed successfully', 'restaurant-booking')));
        } else {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
    }
    
    public function admin_cancel_booking() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'restaurant-booking'));
        }
        
        if (!check_ajax_referer('rb_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }
        
        $booking_id = intval($_POST['booking_id']);
        
        global $rb_booking;
        $result = $rb_booking->cancel_booking($booking_id);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Booking cancelled successfully', 'restaurant-booking')));
        } else {
            wp_send_json_error(array('message' => __('Failed to cancel booking', 'restaurant-booking')));
        }
    }
    
    public function admin_complete_booking() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'restaurant-booking'));
        }
        
        if (!check_ajax_referer('rb_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }
        
        $booking_id = intval($_POST['booking_id']);
        
        global $rb_booking;
        $result = $rb_booking->complete_booking($booking_id);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Booking marked as completed', 'restaurant-booking')));
        } else {
            wp_send_json_error(array('message' => __('Failed to complete booking', 'restaurant-booking')));
        }
    }
    
    public function admin_delete_booking() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'restaurant-booking'));
        }
        
        if (!check_ajax_referer('rb_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }
        
        $booking_id = intval($_POST['booking_id']);
        
        global $rb_booking;
        $result = $rb_booking->delete_booking($booking_id);
        
        if ($result) {
            wp_send_json_success(array('message' => __('Booking deleted successfully', 'restaurant-booking')));
        } else {
            wp_send_json_error(array('message' => __('Failed to delete booking', 'restaurant-booking')));
        }
    }
    
    public function admin_toggle_table() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'restaurant-booking'));
        }
        
        if (!check_ajax_referer('rb_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }
        
        $table_id = intval($_POST['table_id']);
        $is_available = intval($_POST['is_available']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'rb_tables';
        
        $result = $wpdb->update(
            $table_name,
            array('is_available' => $is_available),
            array('id' => $table_id)
        );
        
        if ($result !== false) {
            wp_send_json_success(array('message' => __('Table status updated', 'restaurant-booking')));
        } else {
            wp_send_json_error(array('message' => __('Failed to update table status', 'restaurant-booking')));
        }
    }
    
    public function admin_add_table() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'restaurant-booking'));
        }
        
        if (!check_ajax_referer('rb_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }
        
        $table_number = intval($_POST['table_number']);
        $capacity = intval($_POST['capacity']);
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        global $rb_location;

        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $location = $rb_location ? $rb_location->get($location_id) : null;

        if (!$location_id || !$location) {
            wp_send_json_error(array('message' => __('Please select a valid location.', 'restaurant-booking')));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rb_tables';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE table_number = %d AND location_id = %d",
            $table_number,
            $location_id
        ));

        if ($exists) {
            wp_send_json_error(array('message' => __('Table number already exists', 'restaurant-booking')));
        }
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'location_id' => $location_id,
                'table_number' => $table_number,
                'capacity' => $capacity,
                'is_available' => 1,
                'created_at' => current_time('mysql')
            )
        );
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Table added successfully', 'restaurant-booking'),
                'table_id' => $wpdb->insert_id
            ));
        } else {
            wp_send_json_error(array('message' => __('Failed to add table', 'restaurant-booking')));
        }
    }
    
    public function admin_delete_table() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'restaurant-booking'));
        }
        
        if (!check_ajax_referer('rb_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }
        
        $table_id = intval($_POST['table_id']);
        
        global $wpdb;
        $tables_table = $wpdb->prefix . 'rb_tables';
        $bookings_table = $wpdb->prefix . 'rb_bookings';
        
        $table = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $tables_table WHERE id = %d",
            $table_id
        ));
        
        if ($table) {
            $active_bookings = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $bookings_table 
                WHERE table_number = %d 
                AND status IN ('pending', 'confirmed')
                AND booking_date >= CURDATE()",
                $table->table_number
            ));
            
            if ($active_bookings > 0) {
                wp_send_json_error(array('message' => __('Cannot delete table with active bookings', 'restaurant-booking')));
            }
            
            $result = $wpdb->delete($tables_table, array('id' => $table_id));
            
            if ($result) {
                wp_send_json_success(array('message' => __('Table deleted successfully', 'restaurant-booking')));
            } else {
                wp_send_json_error(array('message' => __('Failed to delete table', 'restaurant-booking')));
            }
        } else {
            wp_send_json_error(array('message' => __('Table not found', 'restaurant-booking')));
        }
    }
    
    public function get_customer_history() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'restaurant-booking'));
        }
        
        if (!check_ajax_referer('rb_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }
        
        $phone = sanitize_text_field($_POST['phone']);
        
        global $rb_customer;
        $history = $rb_customer->get_customer_history($phone);
        
        if ($history) {
            wp_send_json_success(array(
                'message' => __('History loaded', 'restaurant-booking'),
                'history' => $history
            ));
        } else {
            wp_send_json_error(array('message' => __('No history found', 'restaurant-booking')));
        }
    }

    /**
     * Set customer VIP status
     */
    public function set_customer_vip() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'restaurant-booking'));
        }
        
        if (!check_ajax_referer('rb_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }
        
        $customer_id = intval($_POST['customer_id']);
        $status = intval($_POST['status']);
        
        global $rb_customer;
        $result = $rb_customer->set_vip_status($customer_id, $status);
        
        if ($result !== false) {
            $message = $status ? 'Đã nâng cấp VIP' : 'Đã bỏ VIP';
            wp_send_json_success(array('message' => $message));
        } else {
            wp_send_json_error(array('message' => __('Failed to update VIP status', 'restaurant-booking')));
        }
    }

    /**
     * Set customer blacklist status
     */
    public function set_customer_blacklist() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'restaurant-booking'));
        }

        if (!check_ajax_referer('rb_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }

        $customer_id = intval($_POST['customer_id']);
        $status = intval($_POST['status']);

        global $rb_customer;
        $result = $rb_customer->set_blacklist($customer_id, $status);

        if ($result !== false) {
            $message = $status ? 'Đã blacklist khách hàng' : 'Đã bỏ blacklist';
            wp_send_json_success(array('message' => $message));
        } else {
            wp_send_json_error(array('message' => __('Failed to update blacklist status', 'restaurant-booking')));
        }
    }

    /**
     * Update internal customer note from admin.
     */
    public function update_customer_note() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'restaurant-booking'));
        }

        if (!check_ajax_referer('rb_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }

        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        if (!$customer_id) {
            wp_send_json_error(array('message' => __('Invalid customer.', 'restaurant-booking')));
        }

        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        $updated = $rb_customer->update_customer_notes($customer_id, $note);

        if ($updated === false) {
            wp_send_json_error(array('message' => __('Could not save note. Please try again.', 'restaurant-booking')));
        }

        wp_send_json_success(array('message' => __('Customer note saved successfully.', 'restaurant-booking')));
    }

    /**
     * Get customer statistics
     */
    public function get_customer_stats() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'restaurant-booking'));
        }
        
        if (!check_ajax_referer('rb_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }
        
        global $rb_customer;
        $stats = $rb_customer->get_stats();
        
        wp_send_json_success(array(
            'message' => __('Stats loaded', 'restaurant-booking'),
            'stats' => $stats
        ));
    }

    /**
     * NEW: Cleanup old bookings (older than 6 months)
     */
    public function cleanup_old_bookings() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'restaurant-booking'));
        }
        
        if (!check_ajax_referer('rb_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'rb_bookings';
        
        $six_months_ago = date('Y-m-d', strtotime('-6 months'));
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE booking_date < %s AND status IN ('completed', 'cancelled')",
                $six_months_ago
            )
        );
        
        if ($deleted !== false) {
            wp_send_json_success(array(
                'deleted' => $deleted,
                'message' => sprintf('Đã xóa %d booking cũ', $deleted)
            ));
        } else {
            wp_send_json_error(array('message' => __('Failed to cleanup bookings', 'restaurant-booking')));
        }
    }

    /**
     * NEW: Reset entire plugin data
     */
    public function reset_plugin() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'restaurant-booking'));
        }
        
        if (!check_ajax_referer('rb_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }
        
        global $wpdb;
        
        // Truncate all tables
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rb_bookings");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rb_tables");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rb_customers");
        
        // Reset settings to defaults
        $default_settings = array(
            'working_hours_mode' => 'simple',
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'lunch_break_enabled' => 'no',
            'time_slot_interval' => 30,
            'max_guests_per_booking' => 20,
            'admin_email' => get_option('admin_email'),
            'enable_email' => 'yes',
        );
        
        update_option('rb_settings', $default_settings);
        
        wp_send_json_success(array(
            'message' => 'Plugin đã được reset hoàn toàn! Tất cả dữ liệu đã bị xóa.'
        ));
    }
}