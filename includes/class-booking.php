<?php
/**
 * Booking Class - Xử lý logic đặt bàn
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Booking {
    
    private $wpdb;
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
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
            'confirmation_token_expires' => gmdate('Y-m-d H:i:s', current_time('timestamp', true) + DAY_IN_SECONDS)
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

        // Chọn bàn nhỏ nhất đủ chỗ
        $slot_table = $this->get_smallest_available_table($bk->booking_date, $bk->booking_time, (int)$bk->guest_count, (int)$bk->location_id);
        if (!$slot_table) {
            return new WP_Error('rb_no_table', 'Hết bàn phù hợp để xác nhận ở khung giờ này.');
        }

        $ok = $wpdb->update(
            $b_tbl,
            array(
                'status' => 'confirmed',
                'table_number' => (int)$slot_table->table_number,
                'confirmed_at' => current_time('mysql'),
            ),
            array('id' => (int)$booking_id),
            array('%s', '%d', '%s'),
            array('%d')
        );

        if (false === $ok) {
            return new WP_Error('rb_update_fail', 'Xác nhận thất bại, vui lòng thử lại.');
        }

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
    
    public function is_time_slot_available($date, $time, $guest_count, $exclude_booking_id = null, $location_id = 1) {
        global $wpdb;
        $tables_table = $wpdb->prefix . 'rb_tables';
        $bookings_table = $wpdb->prefix . 'rb_bookings';

        // Tính tổng sức chứa
        $total_capacity = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(capacity) FROM {$tables_table} WHERE is_available = 1 AND location_id = %d",
            $location_id
        ));

        if ($total_capacity <= 0) {
            return false;
        }

        // Tính tổng số khách đã book (pending + confirmed)
        $exclude_sql = '';
        if (null !== $exclude_booking_id) {
            $exclude_sql = $wpdb->prepare(' AND id != %d', (int) $exclude_booking_id);
        }

        $booked_guests = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(guest_count)
            FROM {$bookings_table}
            WHERE booking_date = %s
            AND booking_time = %s
            AND location_id = %d
            AND status IN ('pending', 'confirmed')
            {$exclude_sql}",
            $date, $time, $location_id
        ));

        $remaining_capacity = $total_capacity - $booked_guests;
        if ($remaining_capacity < $guest_count) {
            return false;
        }

        // Đảm bảo còn bàn phù hợp với số lượng khách
        $available_table = $this->get_smallest_available_table($date, $time, $guest_count, $location_id, $exclude_booking_id);

        return !empty($available_table);
    }

    public function get_smallest_available_table($date, $time, $guest_count, $location_id = 1, $exclude_booking_id = null) {
        global $wpdb;
        $t = $wpdb->prefix . 'rb_tables';
        $b = $wpdb->prefix . 'rb_bookings';

        $exclude_sql = '';
        $params = array((int)$location_id, (int)$guest_count, $date, $time, (int)$location_id);

        if (null !== $exclude_booking_id) {
            $exclude_sql = ' AND b.id != %d';
            $params[] = (int) $exclude_booking_id;
        }

        $sql = $wpdb->prepare(
            "SELECT t.table_number, t.capacity
             FROM {$t} t
             WHERE t.is_available = 1
               AND t.location_id = %d
               AND t.capacity >= %d
               AND t.table_number NOT IN (
                 SELECT b.table_number
                 FROM {$b} b
                 WHERE b.booking_date = %s
                   AND b.booking_time = %s
                   AND b.location_id = %d
                   AND b.status IN ('confirmed', 'pending')
                   AND b.table_number IS NOT NULL
                   {$exclude_sql}
               )
             ORDER BY t.capacity ASC, t.table_number ASC
             LIMIT 1",
            $params
        );

        return $wpdb->get_row($sql);
    }

    public function available_table_count($date, $time, $guest_count, $location_id = 1) {
        global $wpdb;
        $t = $wpdb->prefix . 'rb_tables';
        $b = $wpdb->prefix . 'rb_bookings';

        $sql = $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$t} x
             WHERE x.is_available = 1
               AND x.location_id = %d
               AND x.capacity >= %d
               AND x.table_number NOT IN (
                 SELECT y.table_number
                 FROM {$b} y
                 WHERE y.booking_date = %s
                   AND y.booking_time = %s
                   AND y.location_id = %d
                   AND y.status IN ('confirmed', 'pending')
                   AND y.table_number IS NOT NULL
               )",
            (int)$location_id, (int)$guest_count, $date, $time, (int)$location_id
        );

        return (int) $wpdb->get_var($sql);
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
