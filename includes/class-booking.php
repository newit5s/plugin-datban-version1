<?php
/**
 * Booking Class - Xử lý logic đặt bàn
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Booking {

    private $wpdb;
    private $timezone;

    const EARLY_ARRIVAL_BUFFER_MINUTES = 15;
    const POST_CHECKOUT_BUFFER_MINUTES = 15;
    const CLEANUP_BUFFER_MINUTES = 60;
    const MIN_DURATION_MINUTES = 60;
    const MAX_DURATION_MINUTES = 360;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->timezone = $this->determine_timezone();

        if (!has_action('rb_mark_table_available', array($this, 'handle_table_cleanup_complete'))) {
            add_action('rb_mark_table_available', array($this, 'handle_table_cleanup_complete'));
        }
    }
    
    public function get_booking($booking_id) {
        $table_name = $this->wpdb->prefix . 'rb_bookings';
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $booking_id
        ));
    }

    public function get_bookings($args = array()) {
        $defaults = array(
            'status' => '',
            'date' => '',
            'date_from' => '',
            'date_to' => '',
            'location_id' => 0,
            'limit' => -1,
            'offset' => 0,
            'orderby' => 'booking_date',
            'order' => 'DESC',
            'source' => '',
            'search' => '',
        );

        $args = wp_parse_args($args, $defaults);
        $table_name = $this->wpdb->prefix . 'rb_bookings';

        $where_clauses = array('1=1');
        $where_params = array();

        if (!empty($args['status'])) {
            $where_clauses[] = 'status = %s';
            $where_params[] = $args['status'];
        }

        if (!empty($args['date'])) {
            $where_clauses[] = 'booking_date = %s';
            $where_params[] = $args['date'];
        }

        if (!empty($args['date_from']) && !empty($args['date_to'])) {
            $where_clauses[] = 'booking_date BETWEEN %s AND %s';
            $where_params[] = $args['date_from'];
            $where_params[] = $args['date_to'];
        } elseif (!empty($args['date_from'])) {
            $where_clauses[] = 'booking_date >= %s';
            $where_params[] = $args['date_from'];
        } elseif (!empty($args['date_to'])) {
            $where_clauses[] = 'booking_date <= %s';
            $where_params[] = $args['date_to'];
        }

        if (!empty($args['source'])) {
            $where_clauses[] = 'booking_source = %s';
            $where_params[] = $args['source'];
        }

        if (!empty($args['search'])) {
            $search = '%' . $this->wpdb->esc_like($args['search']) . '%';
            $where_clauses[] = '(customer_name LIKE %s OR customer_phone LIKE %s OR customer_email LIKE %s OR CAST(id AS CHAR) LIKE %s)';
            $where_params[] = $search;
            $where_params[] = $search;
            $where_params[] = $search;
            $where_params[] = $search;
        }

        if (!empty($args['location_id'])) {
            $where_clauses[] = 'location_id = %d';
            $where_params[] = (int) $args['location_id'];
        }

        $where = implode(' AND ', $where_clauses);

        $allowed_orderby = array('id', 'customer_name', 'booking_date', 'booking_time', 'guest_count', 'status', 'booking_source', 'created_at');
        if (!in_array($args['orderby'], $allowed_orderby, true)) {
            $args['orderby'] = 'booking_date';
        }

        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $order);

        $sql = "SELECT * FROM $table_name WHERE $where ORDER BY $orderby";

        if ($args['limit'] > 0) {
            $sql .= $this->wpdb->prepare(' LIMIT %d OFFSET %d', $args['limit'], $args['offset']);
        }

        if (!empty($where_params)) {
            return $this->wpdb->get_results($this->wpdb->prepare($sql, $where_params));
        }

        return $this->wpdb->get_results($sql);
    }

    public function get_location_stats($location_id = 0) {
        $table_name = $this->wpdb->prefix . 'rb_bookings';
        $where = '1=1';
        $params = array();

        if ($location_id) {
            $where = 'location_id = %d';
            $params[] = (int) $location_id;
        }

        $today = date('Y-m-d');

        $stats = array(
            'total' => $this->prepare_and_get_var("SELECT COUNT(*) FROM $table_name WHERE $where", $params),
            'pending' => $this->prepare_and_get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending' AND $where", $params),
            'confirmed' => $this->prepare_and_get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'confirmed' AND $where", $params),
            'completed' => $this->prepare_and_get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'completed' AND $where", $params),
            'cancelled' => $this->prepare_and_get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'cancelled' AND $where", $params),
            'today' => $this->prepare_and_get_var(
                "SELECT COUNT(*) FROM $table_name WHERE $where AND booking_date = %s",
                array_merge($params, array($today))
            ),
            'today_pending' => $this->prepare_and_get_var(
                "SELECT COUNT(*) FROM $table_name WHERE status = 'pending' AND $where AND booking_date = %s",
                array_merge($params, array($today))
            ),
            'today_confirmed' => $this->prepare_and_get_var(
                "SELECT COUNT(*) FROM $table_name WHERE status = 'confirmed' AND $where AND booking_date = %s",
                array_merge($params, array($today))
            ),
            'today_completed' => $this->prepare_and_get_var(
                "SELECT COUNT(*) FROM $table_name WHERE status = 'completed' AND $where AND booking_date = %s",
                array_merge($params, array($today))
            ),
            'today_cancelled' => $this->prepare_and_get_var(
                "SELECT COUNT(*) FROM $table_name WHERE status = 'cancelled' AND $where AND booking_date = %s",
                array_merge($params, array($today))
            ),
        );

        return $stats;
    }

    public function get_source_stats($location_id = 0) {
        $table_name = $this->wpdb->prefix . 'rb_bookings';
        $where = '';
        $params = array();

        if ($location_id) {
            $where = 'WHERE location_id = %d';
            $params[] = (int) $location_id;
        }

        $sql = "SELECT booking_source, COUNT(*) as total FROM $table_name $where GROUP BY booking_source ORDER BY total DESC";

        if (!empty($params)) {
            return $this->wpdb->get_results($this->wpdb->prepare($sql, $params));
        }

        return $this->wpdb->get_results($sql);
    }
    
    public function create_booking($data) {
        $table_name = $this->wpdb->prefix . 'rb_bookings';

        $defaults = array(
            'status' => 'pending',
            'booking_source' => 'website',
            'created_at' => current_time('mysql'),
            'table_number' => null,
            'location_id' => 1,
            'language' => rb_get_current_language(),
            'confirmation_token' => $this->generate_confirmation_token(),
            'confirmation_token_expires' => gmdate('Y-m-d H:i:s', current_time('timestamp', true) + DAY_IN_SECONDS),
            'checkin_time' => null,
            'checkout_time' => null,
            'actual_checkin' => null,
            'actual_checkout' => null,
            'cleanup_completed_at' => null,
        );

        $data = wp_parse_args($data, $defaults);

        // Validate required fields
        $required = array('customer_name', 'customer_phone', 'guest_count', 'booking_date', 'booking_time', 'location_id');

        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', sprintf(__('Field %s is required', 'restaurant-booking'), $field));
            }
        }

        if (!empty($data['customer_email']) && !is_email($data['customer_email'])) {
            return new WP_Error('invalid_email', __('Customer email is invalid', 'restaurant-booking'));
        }

        $date = sanitize_text_field($data['booking_date']);
        $location_id = (int) $data['location_id'];
        $guest_count = (int) $data['guest_count'];

        $normalized_checkin = $this->normalize_time_input(!empty($data['checkin_time']) ? $data['checkin_time'] : $data['booking_time']);
        if (!$normalized_checkin) {
            return new WP_Error('invalid_checkin_time', __('Invalid check-in time.', 'restaurant-booking'));
        }

        $checkin_timestamp = $this->parse_time_to_timestamp($date, $normalized_checkin);
        if (!$checkin_timestamp) {
            return new WP_Error('invalid_checkin_time', __('Invalid check-in time.', 'restaurant-booking'));
        }

        $normalized_checkout = $this->normalize_time_input($data['checkout_time']);
        if (!$normalized_checkout) {
            $default_checkout_timestamp = $checkin_timestamp + (2 * HOUR_IN_SECONDS);
            $normalized_checkout = $this->format_time($default_checkout_timestamp);
        }

        $checkout_timestamp = $this->parse_time_to_timestamp($date, $normalized_checkout);
        if (!$checkout_timestamp) {
            return new WP_Error('invalid_checkout_time', __('Invalid checkout time.', 'restaurant-booking'));
        }

        if ($checkout_timestamp <= $checkin_timestamp) {
            return new WP_Error('invalid_duration', __('Checkout time must be later than check-in time.', 'restaurant-booking'));
        }

        $duration_minutes = ($checkout_timestamp - $checkin_timestamp) / MINUTE_IN_SECONDS;
        if ($duration_minutes < self::MIN_DURATION_MINUTES) {
            return new WP_Error('duration_too_short', __('Booking duration must be at least 1 hour.', 'restaurant-booking'));
        }

        if ($duration_minutes > self::MAX_DURATION_MINUTES) {
            return new WP_Error('duration_too_long', __('Booking duration must not exceed 6 hours.', 'restaurant-booking'));
        }

        $data['booking_time'] = $normalized_checkin;
        $data['checkin_time'] = $normalized_checkin;
        $data['checkout_time'] = $normalized_checkout;

        if (!$this->is_time_slot_available($date, $normalized_checkin, $guest_count, $normalized_checkin, $normalized_checkout, null, $location_id)) {
            return new WP_Error('time_slot_unavailable', __('Selected time slot is not available.', 'restaurant-booking'));
        }

        $time_window = $this->calculate_time_window($date, $normalized_checkin, $normalized_checkout);
        if (!empty($time_window['actual_checkin'])) {
            $data['actual_checkin'] = $this->format_datetime($time_window['actual_checkin']);
        }

        if (!empty($time_window['actual_checkout'])) {
            $data['actual_checkout'] = $this->format_datetime($time_window['actual_checkout']);
            $data['cleanup_completed_at'] = $data['actual_checkout'];
        }

        $result = $this->wpdb->insert($table_name, $data);

        if ($result === false) {
            return new WP_Error('db_error', __('Could not create booking', 'restaurant-booking'));
        }

        $booking_id = $this->wpdb->insert_id;

        // *** THAY ĐỔI CHÍNH: Đảm bảo class Customer được load và khởi tạo ***
        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        // Tự động cập nhật thông tin khách hàng vào CRM
        $rb_customer->update_customer_from_booking($booking_id);

        $booking = $this->get_booking($booking_id);
        /**
         * Fires right after a booking has been created.
         *
         * @param int   $booking_id Newly created booking ID.
         * @param object $booking   Booking record.
         */
        do_action('rb_booking_created', $booking_id, $booking);

        return $booking_id;
    }
    
    public function update_booking($booking_id, $data) {
        $table_name = $this->wpdb->prefix . 'rb_bookings';
        
        $result = $this->wpdb->update(
            $table_name,
            $data,
            array('id' => $booking_id)
        );
        
        return $result !== false;
    }
    
    public function delete_booking($booking_id) {
        $table_name = $this->wpdb->prefix . 'rb_bookings';
        return $this->wpdb->delete($table_name, array('id' => $booking_id));
    }
    
    public function confirm_booking($booking_id) {
        global $wpdb;
        $b_tbl = $wpdb->prefix . 'rb_bookings';

        $bk = $this->get_booking($booking_id);
        if (!$bk || $bk->status === 'confirmed') {
            return new WP_Error('rb_invalid', 'Booking không tồn tại hoặc đã confirmed.');
        }

        $checkin_time = !empty($bk->checkin_time) ? $bk->checkin_time : $bk->booking_time;
        $checkout_time = !empty($bk->checkout_time) ? $bk->checkout_time : null;

        $slot_table = $this->get_smallest_available_table(
            $bk->booking_date,
            $bk->booking_time,
            (int) $bk->guest_count,
            (int) $bk->location_id,
            (int) $bk->id,
            $checkin_time,
            $checkout_time
        );

        if (!$slot_table) {
            return new WP_Error('rb_no_table', 'Hết bàn phù hợp để xác nhận ở khung giờ này.');
        }

        $ok = $wpdb->update(
            $b_tbl,
            array(
                'status' => 'confirmed',
                'table_number' => (int) $slot_table->table_number,
                'confirmed_at' => current_time('mysql'),
            ),
            array('id' => (int) $booking_id),
            array('%s', '%d', '%s'),
            array('%d')
        );

        if (false === $ok) {
            return new WP_Error('rb_update_fail', 'Xác nhận thất bại, vui lòng thử lại.');
        }

        $this->update_table_status((int) $slot_table->id, 'reserved', (int) $booking_id);

        $updated_booking = $this->get_booking($booking_id);

        /**
         * Fires when a booking is confirmed successfully.
         *
         * @param int    $booking_id Booking ID.
         * @param object $booking    Booking record with latest data.
         */
        do_action('rb_booking_confirmed', $booking_id, $updated_booking);

        return true;
    }

    public function cancel_booking($booking_id) {
        $result = $this->update_booking($booking_id, array('status' => 'cancelled'));

        // Đánh dấu booking đã hủy trong CRM
        if ($result && class_exists('RB_Customer')) {
            global $rb_customer;
            if ($rb_customer) {
                $rb_customer->mark_cancelled($booking_id);
            }
        }

        if ($result) {
            $booking = $this->get_booking($booking_id);

            /**
             * Fires after a booking has been cancelled.
             *
             * @param int    $booking_id Booking ID.
             * @param object $booking    Booking record.
             */
            do_action('rb_booking_cancelled', $booking_id, $booking);
        }

        return $result;
    }

    public function complete_booking($booking_id) {
        $result = $this->update_booking($booking_id, array('status' => 'completed'));

        // Đánh dấu booking đã hoàn thành trong CRM
        if ($result && class_exists('RB_Customer')) {
            global $rb_customer;
            if ($rb_customer) {
                $rb_customer->mark_completed($booking_id);
            }
        }

        if ($result) {
            $booking = $this->get_booking($booking_id);
            do_action('rb_booking_completed', $booking_id, $booking);
        }

        return $result;
    }
    
    /**
     * Đánh dấu no-show (khách đặt nhưng không đến)
     */
    public function mark_no_show($booking_id) {
        $result = $this->update_booking($booking_id, array('status' => 'no-show'));
        
        if ($result && class_exists('RB_Customer')) {
            global $rb_customer;
            if ($rb_customer) {
                $rb_customer->mark_no_show($booking_id);
            }
        }
        
        return $result;
    }
    
    public function check_time_overlap($date, $checkin_time, $checkout_time, $location_id, $exclude_booking_id = null) {
        $normalized_checkin = $this->normalize_time_input($checkin_time);
        $normalized_checkout = $this->normalize_time_input($checkout_time);

        if (!$normalized_checkin || !$normalized_checkout) {
            return true;
        }

        $window = $this->calculate_time_window($date, $normalized_checkin, $normalized_checkout);
        if (empty($window['actual_checkin']) || empty($window['actual_checkout'])) {
            return true;
        }

        $bookings_table = $this->wpdb->prefix . 'rb_bookings';
        $params = array($date, (int) $location_id);
        $sql = "SELECT id, booking_time, checkin_time, checkout_time, actual_checkin, actual_checkout, cleanup_completed_at FROM {$bookings_table} WHERE booking_date = %s AND location_id = %d AND status IN ('pending','confirmed')";

        if (!empty($exclude_booking_id)) {
            $sql .= ' AND id != %d';
            $params[] = (int) $exclude_booking_id;
        }

        $existing_bookings = $this->wpdb->get_results($this->wpdb->prepare($sql, $params));

        if (empty($existing_bookings)) {
            return false;
        }

        $overlap_count = 0;

        foreach ($existing_bookings as $booking) {
            $existing_window = $this->calculate_time_window(
                $date,
                !empty($booking->checkin_time) ? $booking->checkin_time : $booking->booking_time,
                !empty($booking->checkout_time) ? $booking->checkout_time : $booking->booking_time,
                $booking->actual_checkin,
                $booking->actual_checkout,
                $booking->cleanup_completed_at
            );

            if (empty($existing_window['actual_checkin']) || empty($existing_window['actual_checkout'])) {
                return true;
            }

            if ($window['actual_checkin'] < $existing_window['actual_checkout'] && $window['actual_checkout'] > $existing_window['actual_checkin']) {
                $overlap_count++;
            }
        }

        if (0 === $overlap_count) {
            return false;
        }

        $tables_table = $this->wpdb->prefix . 'rb_tables';
        $total_tables = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$tables_table} WHERE is_available = 1 AND location_id = %d",
            (int) $location_id
        ));

        if ($total_tables <= 0) {
            return true;
        }

        return $overlap_count >= $total_tables;
    }

    public function is_time_slot_available($date, $time, $guest_count, $checkin_time = null, $checkout_time = null, $exclude_booking_id = null, $location_id = 1) {
        $args_count = func_num_args();
        if ($args_count === 4) {
            $exclude_booking_id = $checkin_time;
            $checkin_time = null;
        } elseif ($args_count === 5 && ($checkin_time === null || is_numeric($checkin_time)) && ($checkout_time === null || is_numeric($checkout_time))) {
            $exclude_booking_id = $checkin_time;
            $location_id = (int) $checkout_time;
            $checkin_time = null;
            $checkout_time = null;
        }

        $location_id = (int) $location_id;
        $guest_count = (int) $guest_count;

        $tables_table = $this->wpdb->prefix . 'rb_tables';
        $total_capacity = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT SUM(capacity) FROM {$tables_table} WHERE is_available = 1 AND location_id = %d",
            $location_id
        ));

        if ($total_capacity <= 0) {
            return false;
        }

        $normalized_checkin = $this->normalize_time_input($checkin_time ?: $time);
        if (!$normalized_checkin) {
            return false;
        }

        $checkin_timestamp = $this->parse_time_to_timestamp($date, $normalized_checkin);
        if (!$checkin_timestamp) {
            return false;
        }

        $normalized_checkout = $this->normalize_time_input($checkout_time);
        if (!$normalized_checkout) {
            $normalized_checkout = $this->format_time($checkin_timestamp + (2 * HOUR_IN_SECONDS));
        }

        $checkout_timestamp = $this->parse_time_to_timestamp($date, $normalized_checkout);
        if (!$checkout_timestamp) {
            return false;
        }

        if ($checkout_timestamp <= $checkin_timestamp) {
            return false;
        }

        $duration_minutes = ($checkout_timestamp - $checkin_timestamp) / MINUTE_IN_SECONDS;
        if ($duration_minutes < self::MIN_DURATION_MINUTES || $duration_minutes > self::MAX_DURATION_MINUTES) {
            return false;
        }

        if (!$this->is_within_working_hours($date, $checkin_timestamp, $checkout_timestamp, $location_id)) {
            return false;
        }

        if ($this->check_time_overlap($date, $normalized_checkin, $normalized_checkout, $location_id, $exclude_booking_id)) {
            return false;
        }

        $window = $this->calculate_time_window($date, $normalized_checkin, $normalized_checkout);
        if (empty($window['actual_checkin']) || empty($window['actual_checkout'])) {
            return false;
        }

        $available_tables = $this->get_tables_for_time_range(
            $date,
            $location_id,
            $guest_count,
            $window['actual_checkin'],
            $window['actual_checkout'],
            $exclude_booking_id
        );

        return !empty($available_tables);
    }

    public function get_smallest_available_table($date, $time, $guest_count, $location_id = 1, $exclude_booking_id = null, $checkin_time = null, $checkout_time = null) {
        $location_id = (int) $location_id;
        $guest_count = (int) $guest_count;

        $normalized_checkin = $this->normalize_time_input($checkin_time ?: $time);
        if (!$normalized_checkin) {
            return null;
        }

        $checkin_timestamp = $this->parse_time_to_timestamp($date, $normalized_checkin);
        if (!$checkin_timestamp) {
            return null;
        }

        $normalized_checkout = $this->normalize_time_input($checkout_time);
        if (!$normalized_checkout) {
            $normalized_checkout = $this->format_time($checkin_timestamp + (2 * HOUR_IN_SECONDS));
        }

        $window = $this->calculate_time_window($date, $normalized_checkin, $normalized_checkout);
        if (empty($window['actual_checkin']) || empty($window['actual_checkout'])) {
            return null;
        }

        $tables = $this->get_tables_for_time_range(
            $date,
            $location_id,
            $guest_count,
            $window['actual_checkin'],
            $window['actual_checkout'],
            $exclude_booking_id
        );

        return !empty($tables) ? $tables[0] : null;
    }

    public function available_table_count($date, $time, $guest_count, $location_id = 1, $checkin_time = null, $checkout_time = null, $exclude_booking_id = null) {
        $location_id = (int) $location_id;
        $guest_count = (int) $guest_count;

        $normalized_checkin = $this->normalize_time_input($checkin_time ?: $time);
        if (!$normalized_checkin) {
            return 0;
        }

        $checkin_timestamp = $this->parse_time_to_timestamp($date, $normalized_checkin);
        if (!$checkin_timestamp) {
            return 0;
        }

        $normalized_checkout = $this->normalize_time_input($checkout_time);
        if (!$normalized_checkout) {
            $normalized_checkout = $this->format_time($checkin_timestamp + (2 * HOUR_IN_SECONDS));
        }

        $window = $this->calculate_time_window($date, $normalized_checkin, $normalized_checkout);
        if (empty($window['actual_checkin']) || empty($window['actual_checkout'])) {
            return 0;
        }

        $tables = $this->get_tables_for_time_range(
            $date,
            $location_id,
            $guest_count,
            $window['actual_checkin'],
            $window['actual_checkout'],
            $exclude_booking_id
        );

        return count($tables);
    }

    public function get_timeline_data($date, $location_id) {
        $date = sanitize_text_field($date);
        $location_id = (int) $location_id;

        if (empty($date) || $location_id <= 0) {
            return array();
        }

        $settings = $this->get_location_settings($location_id);
        if (empty($settings)) {
            return array();
        }

        $interval = isset($settings['time_slot_interval']) ? (int) $settings['time_slot_interval'] : 30;
        if ($interval <= 0) {
            $interval = 30;
        }

        $time_slots = $this->generate_time_slots_for_location($settings['opening_time'], $settings['closing_time'], $interval);

        $tables_table = $this->wpdb->prefix . 'rb_tables';
        $tables = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, table_number, capacity, current_status FROM {$tables_table} WHERE location_id = %d ORDER BY table_number ASC",
            $location_id
        ));

        $tables_data = array();
        $table_lookup = array();

        foreach ($tables as $table) {
            $key = 'table_' . (int) $table->id;
            $tables_data[$key] = array(
                'id' => (int) $table->id,
                'table_number' => (int) $table->table_number,
                'capacity' => (int) $table->capacity,
                'current_status' => !empty($table->current_status) ? $table->current_status : 'available',
                'bookings' => array(),
            );
            $table_lookup[(int) $table->table_number] = $key;
        }

        $bookings_table = $this->wpdb->prefix . 'rb_bookings';
        $bookings = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, customer_name, customer_phone, booking_time, checkin_time, checkout_time, actual_checkin, actual_checkout, cleanup_completed_at, status, guest_count, booking_source, table_number FROM {$bookings_table} WHERE booking_date = %s AND location_id = %d AND status IN ('pending','confirmed') ORDER BY checkin_time ASC",
            $date,
            $location_id
        ));

        foreach ($bookings as $booking) {
            if (empty($booking->table_number)) {
                continue;
            }

            $table_number = (int) $booking->table_number;
            if (!isset($table_lookup[$table_number])) {
                continue;
            }

            $window = $this->calculate_time_window(
                $date,
                !empty($booking->checkin_time) ? $booking->checkin_time : $booking->booking_time,
                !empty($booking->checkout_time) ? $booking->checkout_time : $booking->booking_time,
                $booking->actual_checkin,
                $booking->actual_checkout,
                $booking->cleanup_completed_at
            );

            $planned_checkin = $window['checkin'] ? $this->format_time_short($window['checkin']) : '';
            $planned_checkout = $window['checkout'] ? $this->format_time_short($window['checkout']) : '';
            $actual_checkin = $window['actual_checkin'] ? $this->format_time_short($window['actual_checkin']) : '';
            $actual_checkout_ts = $window['actual_checkout'];
            if (!$actual_checkout_ts && !empty($booking->cleanup_completed_at)) {
                $actual_checkout_ts = $this->parse_datetime_value($booking->cleanup_completed_at, $date);
            }
            $actual_checkout = $actual_checkout_ts ? $this->format_time_short($actual_checkout_ts) : '';

            $tables_data[$table_lookup[$table_number]]['bookings'][] = array(
                'booking_id' => (int) $booking->id,
                'customer_name' => $booking->customer_name,
                'phone' => $booking->customer_phone,
                'checkin_time' => $planned_checkin,
                'checkout_time' => $planned_checkout,
                'actual_checkin' => $actual_checkin,
                'actual_checkout' => $actual_checkout,
                'status' => $booking->status,
                'guest_count' => (int) $booking->guest_count,
                'booking_source' => $booking->booking_source,
            );
        }

        foreach ($tables_data as &$table) {
            if (!empty($table['bookings'])) {
                usort($table['bookings'], function ($a, $b) {
                    return strcmp($a['checkin_time'], $b['checkin_time']);
                });
            }
        }
        unset($table);

        return array(
            'date' => $date,
            'location_id' => $location_id,
            'time_slots' => $time_slots,
            'tables' => $tables_data,
        );
    }

    public function update_table_status($table_id, $status, $booking_id = null) {
        $allowed = array('available', 'occupied', 'cleaning', 'reserved');
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $table_id = (int) $table_id;
        if ($table_id <= 0) {
            return false;
        }

        $table_name = $this->wpdb->prefix . 'rb_tables';

        $data = array(
            'current_status' => $status,
            'status_updated_at' => current_time('mysql'),
        );
        $format = array('%s', '%s');

        if ($booking_id !== null) {
            $data['last_booking_id'] = (int) $booking_id;
            $format[] = '%d';
        } else {
            $data['last_booking_id'] = null;
            $format[] = '%d';
        }

        $updated = $this->wpdb->update(
            $table_name,
            $data,
            array('id' => $table_id),
            $format,
            array('%d')
        );

        if ($updated !== false) {
            if ('cleaning' === $status) {
                $this->schedule_table_cleanup_completion($table_id, $booking_id);
            } else {
                $this->clear_table_cleanup_schedule($table_id);
            }
        }

        if ($updated !== false && $booking_id === null) {
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$table_name} SET last_booking_id = NULL WHERE id = %d",
                $table_id
            ));
        }

        return $updated !== false;
    }

    public function mark_checkin($booking_id, $actual_time = null) {
        $booking = $this->get_booking($booking_id);
        if (!$booking) {
            return new WP_Error('rb_booking_not_found', __('Booking not found.', 'restaurant-booking'));
        }

        $timestamp = $this->parse_datetime_value($actual_time, $booking->booking_date);
        if (!$timestamp) {
            $timestamp = current_time('timestamp');
        }

        $updated = $this->update_booking((int) $booking_id, array(
            'actual_checkin' => $this->format_datetime($timestamp),
        ));

        if (!$updated) {
            return new WP_Error('rb_update_fail', __('Could not update booking.', 'restaurant-booking'));
        }

        if (!empty($booking->table_number)) {
            $table_id = $this->get_table_id_by_number((int) $booking->location_id, (int) $booking->table_number);
            if ($table_id) {
                $this->update_table_status($table_id, 'occupied', (int) $booking_id);
            }
        }

        return true;
    }

    public function mark_checkout($booking_id, $actual_time = null) {
        $booking = $this->get_booking($booking_id);
        if (!$booking) {
            return new WP_Error('rb_booking_not_found', __('Booking not found.', 'restaurant-booking'));
        }

        $timestamp = $this->parse_datetime_value($actual_time, $booking->booking_date);
        if (!$timestamp) {
            $timestamp = current_time('timestamp');
        }

        $cleanup_timestamp = $timestamp + (self::CLEANUP_BUFFER_MINUTES * MINUTE_IN_SECONDS);

        $updated = $this->update_booking((int) $booking_id, array(
            'actual_checkout' => $this->format_datetime($timestamp),
            'cleanup_completed_at' => $this->format_datetime($cleanup_timestamp),
        ));

        if (!$updated) {
            return new WP_Error('rb_update_fail', __('Could not update booking.', 'restaurant-booking'));
        }

        if (!empty($booking->table_number)) {
            $table_id = $this->get_table_id_by_number((int) $booking->location_id, (int) $booking->table_number);
            if ($table_id) {
                $this->update_table_status($table_id, 'cleaning', (int) $booking_id);
            }
        }

        return true;
    }

    public function handle_table_cleanup_complete($table_id) {
        $table_id = (int) $table_id;
        if ($table_id <= 0) {
            return;
        }

        $table_name = $this->wpdb->prefix . 'rb_tables';
        $table = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT current_status FROM {$table_name} WHERE id = %d",
            $table_id
        ));

        if (!$table || 'cleaning' !== $table->current_status) {
            return;
        }

        $this->update_table_status($table_id, 'available');
    }

    private function schedule_table_cleanup_completion($table_id, $booking_id = null) {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event') || !function_exists('wp_unschedule_event')) {
            return;
        }

        $table_id = (int) $table_id;
        if ($table_id <= 0) {
            return;
        }

        $schedule_timestamp = current_time('timestamp', true) + (self::CLEANUP_BUFFER_MINUTES * MINUTE_IN_SECONDS);

        if ($booking_id) {
            $booking = $this->get_booking($booking_id);
            if ($booking && !empty($booking->cleanup_completed_at)) {
                $parsed = $this->parse_datetime_value($booking->cleanup_completed_at);
                if ($parsed) {
                    $schedule_timestamp = $parsed;
                }
            }
        }

        $now = current_time('timestamp', true);
        if ($schedule_timestamp <= $now) {
            $schedule_timestamp = $now + MINUTE_IN_SECONDS;
        }

        $args = array($table_id);
        $existing = wp_next_scheduled('rb_mark_table_available', $args);
        if ($existing) {
            wp_unschedule_event($existing, 'rb_mark_table_available', $args);
        }

        wp_schedule_single_event($schedule_timestamp, 'rb_mark_table_available', $args);
    }

    private function clear_table_cleanup_schedule($table_id) {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_unschedule_event')) {
            return;
        }

        $table_id = (int) $table_id;
        if ($table_id <= 0) {
            return;
        }

        $args = array($table_id);
        $existing = wp_next_scheduled('rb_mark_table_available', $args);
        if ($existing) {
            wp_unschedule_event($existing, 'rb_mark_table_available', $args);
        }
    }

    private function get_tables_for_time_range($date, $location_id, $guest_count, $start_timestamp, $end_timestamp, $exclude_booking_id = null) {
        $tables_table = $this->wpdb->prefix . 'rb_tables';
        $bookings_table = $this->wpdb->prefix . 'rb_bookings';

        $tables = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, table_number, capacity, current_status FROM {$tables_table} WHERE is_available = 1 AND location_id = %d ORDER BY capacity ASC, table_number ASC",
            (int) $location_id
        ));

        if (empty($tables)) {
            return array();
        }

        $available = array();

        foreach ($tables as $table) {
            if ((int) $table->capacity < (int) $guest_count) {
                continue;
            }

            $query = "SELECT id, booking_time, checkin_time, checkout_time, actual_checkin, actual_checkout, cleanup_completed_at FROM {$bookings_table} WHERE booking_date = %s AND location_id = %d AND table_number = %d AND status IN ('pending','confirmed')";
            $params = array($date, (int) $location_id, (int) $table->table_number);

            if (!empty($exclude_booking_id)) {
                $query .= ' AND id != %d';
                $params[] = (int) $exclude_booking_id;
            }

            $bookings = $this->wpdb->get_results($this->wpdb->prepare($query, $params));

            $conflict = false;

            if (!empty($bookings)) {
                foreach ($bookings as $booking) {
                    $booking_window = $this->calculate_time_window(
                        $date,
                        !empty($booking->checkin_time) ? $booking->checkin_time : $booking->booking_time,
                        !empty($booking->checkout_time) ? $booking->checkout_time : $booking->booking_time,
                        $booking->actual_checkin,
                        $booking->actual_checkout,
                        $booking->cleanup_completed_at
                    );

                    if (empty($booking_window['actual_checkin']) || empty($booking_window['actual_checkout'])) {
                        $conflict = true;
                        break;
                    }

                    if ($start_timestamp < $booking_window['actual_checkout'] && $end_timestamp > $booking_window['actual_checkin']) {
                        $conflict = true;
                        break;
                    }
                }
            }

            if (!$conflict) {
                $available[] = $table;
            }
        }

        return $available;
    }

    private function get_table_id_by_number($location_id, $table_number) {
        $tables_table = $this->wpdb->prefix . 'rb_tables';
        $table_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$tables_table} WHERE location_id = %d AND table_number = %d",
            (int) $location_id,
            (int) $table_number
        ));

        return $table_id ? (int) $table_id : 0;
    }

    private function get_location_settings($location_id) {
        global $rb_location;

        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        return $rb_location->get_settings($location_id);
    }

    private function normalize_time_input($time) {
        if ($time === null) {
            return null;
        }

        $time = trim((string) $time);
        if ($time === '') {
            return null;
        }

        $timezone = $this->get_timezone();
        $patterns = array('!H:i:s', '!H:i');

        foreach ($patterns as $pattern) {
            $dt = DateTime::createFromFormat($pattern, $time, $timezone);
            if ($dt instanceof DateTime) {
                return $dt->format('H:i:s');
            }
        }

        try {
            $dt = new DateTime($time, $timezone);
            return $dt->format('H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }

    private function parse_time_to_timestamp($date, $time) {
        $normalized = $this->normalize_time_input($time);
        if (!$normalized) {
            return null;
        }

        try {
            $dt = new DateTime($date . ' ' . $normalized, $this->get_timezone());
            return $dt->getTimestamp();
        } catch (Exception $e) {
            return null;
        }
    }

    private function parse_datetime_value($value, $fallback_date = null) {
        if (empty($value)) {
            return null;
        }

        $value = trim((string) $value);
        $timezone = $this->get_timezone();

        try {
            if ($fallback_date && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
                $normalized = $this->normalize_time_input($value);
                if (!$normalized) {
                    return null;
                }

                $dt = new DateTime($fallback_date . ' ' . $normalized, $timezone);
            } else {
                $dt = new DateTime($value, $timezone);
            }

            return $dt->getTimestamp();
        } catch (Exception $e) {
            return null;
        }
    }

    private function format_time($timestamp) {
        if (!$timestamp) {
            return null;
        }

        if (function_exists('wp_date')) {
            return wp_date('H:i:s', $timestamp, $this->get_timezone());
        }

        return date('H:i:s', $timestamp);
    }

    private function format_time_short($timestamp) {
        if (!$timestamp) {
            return '';
        }

        if (function_exists('wp_date')) {
            return wp_date('H:i', $timestamp, $this->get_timezone());
        }

        return date('H:i', $timestamp);
    }

    private function format_datetime($timestamp) {
        if (!$timestamp) {
            return null;
        }

        if (function_exists('wp_date')) {
            return wp_date('Y-m-d H:i:s', $timestamp, $this->get_timezone());
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function calculate_time_window($date, $checkin_time, $checkout_time, $actual_checkin = null, $actual_checkout = null, $cleanup_completed_at = null) {
        $checkin_ts = $this->parse_time_to_timestamp($date, $checkin_time);
        $checkout_ts = $this->parse_time_to_timestamp($date, $checkout_time);

        $actual_checkin_ts = $this->parse_datetime_value($actual_checkin, $date);
        if (!$actual_checkin_ts && $checkin_ts) {
            $actual_checkin_ts = $checkin_ts - (self::EARLY_ARRIVAL_BUFFER_MINUTES * MINUTE_IN_SECONDS);
        }

        $cleanup_ts = $this->parse_datetime_value($cleanup_completed_at, $date);

        $actual_checkout_base = $this->parse_datetime_value($actual_checkout, $date);
        if (!$actual_checkout_base) {
            $actual_checkout_base = $checkout_ts;
        }

        $actual_checkout_ts = null;
        if ($cleanup_ts) {
            $actual_checkout_ts = $cleanup_ts;
        } elseif ($actual_checkout_base) {
            $expected_buffer = (self::POST_CHECKOUT_BUFFER_MINUTES + self::CLEANUP_BUFFER_MINUTES) * MINUTE_IN_SECONDS;
            $planned_diff = ($checkout_ts && $actual_checkout_base) ? $actual_checkout_base - $checkout_ts : 0;

            if ($planned_diff >= $expected_buffer) {
                $actual_checkout_ts = $actual_checkout_base;
            } else {
                $actual_checkout_ts = $actual_checkout_base + $expected_buffer;
            }
        }

        return array(
            'checkin' => $checkin_ts,
            'checkout' => $checkout_ts,
            'actual_checkin' => $actual_checkin_ts,
            'actual_checkout' => $actual_checkout_ts,
        );
    }

    private function is_within_working_hours($date, $start_timestamp, $end_timestamp, $location_id) {
        $settings = $this->get_location_settings($location_id);

        if (empty($settings)) {
            return true;
        }

        $ranges = array();
        $mode = isset($settings['working_hours_mode']) ? $settings['working_hours_mode'] : 'simple';

        if ($mode === 'advanced') {
            $ranges[] = array($settings['morning_shift_start'], $settings['morning_shift_end']);
            $ranges[] = array($settings['evening_shift_start'], $settings['evening_shift_end']);
        } else {
            $opening = $settings['opening_time'];
            $closing = $settings['closing_time'];

            if (!empty($settings['lunch_break_enabled']) && $settings['lunch_break_enabled'] === 'yes') {
                $ranges[] = array($opening, $settings['lunch_break_start']);
                $ranges[] = array($settings['lunch_break_end'], $closing);
            } else {
                $ranges[] = array($opening, $closing);
            }
        }

        foreach ($ranges as $range) {
            $range_start = $this->parse_time_to_timestamp($date, $range[0]);
            $range_end = $this->parse_time_to_timestamp($date, $range[1]);

            if (!$range_start || !$range_end) {
                continue;
            }

            if ($start_timestamp >= $range_start && $end_timestamp <= $range_end) {
                return true;
            }
        }

        return false;
    }

    private function get_timezone() {
        if (!$this->timezone instanceof DateTimeZone) {
            $this->timezone = $this->determine_timezone();
        }

        return $this->timezone;
    }

    private function determine_timezone() {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        $timezone_string = get_option('timezone_string');
        if (!empty($timezone_string)) {
            try {
                return new DateTimeZone($timezone_string);
            } catch (Exception $e) {
                // Ignore và fallback bên dưới
            }
        }

        return new DateTimeZone('UTC');
    }

    public function suggest_time_slots($location_id, $date, $time, $guest_count, $range_minutes = 30) {
        global $rb_location;

        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $settings = $rb_location->get_settings($location_id);

        if (empty($settings)) {
            return array();
        }

        $interval = isset($settings['time_slot_interval']) ? (int) $settings['time_slot_interval'] : 30;
        if ($interval <= 0) {
            $interval = 30;
        }

        $all_slots = $this->generate_time_slots_for_location($settings['opening_time'], $settings['closing_time'], $interval);

        $target_timestamp = strtotime($date . ' ' . $time);
        if (!$target_timestamp) {
            return array();
        }

        $range_seconds = absint($range_minutes) * MINUTE_IN_SECONDS;
        $candidates = array();

        foreach ($all_slots as $slot) {
            $slot_timestamp = strtotime($date . ' ' . $slot);
            if (!$slot_timestamp) {
                continue;
            }

            $difference = abs($slot_timestamp - $target_timestamp);
            if ($difference <= $range_seconds) {
                if ($this->is_time_slot_available($date, $slot, $guest_count, null, $location_id)) {
                    $candidates[] = array(
                        'time' => $slot,
                        'diff' => $difference,
                        'is_after' => $slot_timestamp > $target_timestamp
                    );
                }
            }
        }

        if (empty($candidates)) {
            return array();
        }

        usort($candidates, function($a, $b) {
            if ($a['diff'] === $b['diff']) {
                if ($a['is_after'] === $b['is_after']) {
                    return 0;
                }

                return $a['is_after'] ? 1 : -1;
            }

            return ($a['diff'] < $b['diff']) ? -1 : 1;
        });

        $limited = array_slice($candidates, 0, 2);

        return array_map(function($candidate) {
            return $candidate['time'];
        }, $limited);
    }

    private function generate_time_slots_for_location($opening_time, $closing_time, $interval) {
        $slots = array();
        $current = strtotime($opening_time);
        $end = strtotime($closing_time);

        if ($current === false || $end === false) {
            return $slots;
        }

        while ($current <= $end) {
            $slots[] = date('H:i', $current);
            $current = strtotime("+{$interval} minutes", $current);
        }

        return $slots;
    }

    private function prepare_and_get_var($sql, $params = array()) {
        if (!empty($params)) {
            return $this->wpdb->get_var($this->wpdb->prepare($sql, $params));
        }

        return $this->wpdb->get_var($sql);
    }

    private function generate_confirmation_token() {
        return wp_generate_password(32, false, false);
    }

    public function confirm_booking_by_token($token) {
        if (empty($token)) {
            return new WP_Error('rb_invalid_token', __('Invalid confirmation token', 'restaurant-booking'));
        }

        $table_name = $this->wpdb->prefix . 'rb_bookings';
        $booking = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE confirmation_token = %s",
            $token
        ));

        if (!$booking) {
            return new WP_Error('rb_token_not_found', __('Booking not found or already confirmed', 'restaurant-booking'));
        }

        if (!empty($booking->confirmation_token_expires)) {
            $expires = strtotime($booking->confirmation_token_expires);
            if ($expires && $expires < current_time('timestamp', true)) {
                return new WP_Error('rb_token_expired', __('Confirmation link has expired', 'restaurant-booking'));
            }
        }

        if ($booking->status === 'confirmed') {
            return true;
        }

        $result = $this->confirm_booking($booking->id);

        if (is_wp_error($result)) {
            return $result;
        }

        $this->wpdb->update(
            $table_name,
            array(
                'confirmation_token' => null,
                'confirmation_token_expires' => null,
                'confirmed_via' => 'email'
            ),
            array('id' => $booking->id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        return true;
    }
}
