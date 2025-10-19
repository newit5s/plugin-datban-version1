<?php
class RB_Customer {
    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Tự động cập nhật thông tin khách khi có booking mới hoặc chỉnh sửa.
     *
     * @param int  $booking_id
     * @param bool $is_update  Khi true sẽ không tăng bộ đếm đặt bàn.
     */
    public function update_customer_from_booking($booking_id, $is_update = false) {
        $booking_table = $this->wpdb->prefix . 'rb_bookings';
        $customer_table = $this->wpdb->prefix . 'rb_customers';

        $booking = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM $booking_table WHERE id = %d",
            $booking_id
        ));

        if (!$booking) {
            return false;
        }

        $is_update = (bool) $is_update;
        $location_id = isset($booking->location_id) ? (int) $booking->location_id : 1;

        // Check if customer exists in the same location
        $customer = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM $customer_table WHERE phone = %s AND location_id = %d",
            $booking->customer_phone,
            $location_id
        ));

        if ($customer) {
            if ($is_update) {
                $this->wpdb->update(
                    $customer_table,
                    array(
                        'last_visit' => $booking->booking_date,
                        'name' => $booking->customer_name,
                        'email' => $booking->customer_email,
                        'preferred_source' => $booking->booking_source,
                        'updated_at' => current_time('mysql')
                    ),
                    array(
                        'phone' => $booking->customer_phone,
                        'location_id' => $location_id
                    )
                );
            } else {
                $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE $customer_table SET
                    total_bookings = total_bookings + 1,
                    last_visit = %s,
                    name = %s,
                    email = %s,
                    preferred_source = %s,
                    updated_at = NOW()
                    WHERE phone = %s AND location_id = %d",
                    $booking->booking_date,
                    $booking->customer_name,
                    $booking->customer_email,
                    $booking->booking_source,
                    $booking->customer_phone,
                    $location_id
                ));
            }
        } else {
            $this->wpdb->insert($customer_table, array(
                'location_id' => $location_id,
                'phone' => $booking->customer_phone,
                'email' => $booking->customer_email,
                'name' => $booking->customer_name,
                'total_bookings' => 1,
                'first_visit' => $booking->booking_date,
                'last_visit' => $booking->booking_date,
                'preferred_source' => $booking->booking_source,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ));
        }

        return true;
    }

    /**
     * Cập nhật khi booking completed + Auto VIP
     */
    public function mark_completed($booking_id) {
        $booking = $this->get_booking_with_location($booking_id);
        if ($booking) {
            $customer_table = $this->wpdb->prefix . 'rb_customers';
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE $customer_table SET
                completed_bookings = completed_bookings + 1
                WHERE phone = %s AND location_id = %d",
                $booking->customer_phone,
                (int) $booking->location_id
            ));

            $this->auto_upgrade_vip($booking->customer_phone, (int) $booking->location_id);
        }
    }

    /**
     * Auto upgrade to VIP nếu completed >= 5
     */
    private function auto_upgrade_vip($phone, $location_id) {
        $customer_table = $this->wpdb->prefix . 'rb_customers';

        $customer = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM $customer_table WHERE phone = %s AND location_id = %d",
            $phone,
            $location_id
        ));

        if ($customer && $customer->completed_bookings >= 5 && !$customer->vip_status) {
            $this->wpdb->update(
                $customer_table,
                array('vip_status' => 1),
                array(
                    'phone' => $phone,
                    'location_id' => $location_id
                )
            );

            do_action('rb_customer_upgraded_vip', $customer);
        }
    }

    /**
     * Manual set VIP status
     */
    public function set_vip_status($customer_id, $status) {
        $customer_table = $this->wpdb->prefix . 'rb_customers';

        return $this->wpdb->update(
            $customer_table,
            array('vip_status' => $status ? 1 : 0),
            array('id' => $customer_id)
        );
    }

    /**
     * Blacklist customer
     */
    public function set_blacklist($customer_id, $status) {
        $customer_table = $this->wpdb->prefix . 'rb_customers';

        return $this->wpdb->update(
            $customer_table,
            array('blacklisted' => $status ? 1 : 0),
            array('id' => $customer_id)
        );
    }

    public function update_customer_notes($customer_id, $notes) {
        $customer_table = $this->wpdb->prefix . 'rb_customers';

        return $this->wpdb->update(
            $customer_table,
            array('customer_notes' => $notes),
            array('id' => $customer_id)
        );
    }

    /**
     * Cập nhật khi booking cancelled
     */
    public function mark_cancelled($booking_id) {
        $booking = $this->get_booking_with_location($booking_id);
        if ($booking) {
            $customer_table = $this->wpdb->prefix . 'rb_customers';
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE $customer_table SET
                cancelled_bookings = cancelled_bookings + 1
                WHERE phone = %s AND location_id = %d",
                $booking->customer_phone,
                (int) $booking->location_id
            ));

            $this->check_problematic_customer($booking->customer_phone, (int) $booking->location_id);
        }
    }

    /**
     * Đánh dấu no-show
     */
    public function mark_no_show($booking_id) {
        $booking = $this->get_booking_with_location($booking_id);
        if ($booking) {
            $customer_table = $this->wpdb->prefix . 'rb_customers';
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE $customer_table SET
                no_shows = no_shows + 1
                WHERE phone = %s AND location_id = %d",
                $booking->customer_phone,
                (int) $booking->location_id
            ));

            $this->check_problematic_customer($booking->customer_phone, (int) $booking->location_id);
        }
    }

    /**
     * Check nếu customer có vấn đề (nhiều cancel/no-show)
     */
    private function check_problematic_customer($phone, $location_id) {
        $customer_table = $this->wpdb->prefix . 'rb_customers';

        $customer = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM $customer_table WHERE phone = %s AND location_id = %d",
            $phone,
            $location_id
        ));

        if ($customer && $customer->total_bookings >= 3) {
            $problem_count = $customer->no_shows + $customer->cancelled_bookings;
            $problem_rate = ($problem_count / max(1, $customer->total_bookings)) * 100;

            if ($problem_rate > 50) {
                do_action('rb_problematic_customer_detected', $customer);
            }
        }
    }

    /**
     * Lấy lịch sử khách hàng
     */
    public function get_customer_history($phone, $location_id = null) {
        $booking_table = $this->wpdb->prefix . 'rb_bookings';
        $where = array('customer_phone = %s');
        $params = array($phone);

        if ($location_id) {
            $where[] = 'location_id = %d';
            $params[] = (int) $location_id;
        }

        $sql = "SELECT * FROM $booking_table WHERE " . implode(' AND ', $where) . " ORDER BY booking_date DESC, booking_time DESC LIMIT 20";

        return $this->wpdb->get_results($this->wpdb->prepare($sql, $params));
    }

    /**
     * Gợi ý VIP (khách đặt nhiều)
     */
    public function get_vip_suggestions($location_id = null) {
        $customer_table = $this->wpdb->prefix . 'rb_customers';
        $where = array('completed_bookings >= 5', 'vip_status = 0');
        $params = array();

        if ($location_id) {
            $where[] = 'location_id = %d';
            $params[] = (int) $location_id;
        }

        $sql = "SELECT * FROM $customer_table WHERE " . implode(' AND ', $where) . " ORDER BY completed_bookings DESC LIMIT 20";

        return $this->prepare_and_get_results($sql, $params);
    }

    /**
     * Cảnh báo khách có vấn đề
     */
    public function get_problematic_customers($location_id = null) {
        $customer_table = $this->wpdb->prefix . 'rb_customers';

        $where = array('total_bookings >= 3', '(no_shows + cancelled_bookings) / total_bookings > 0.3');
        $params = array();

        if ($location_id) {
            $where[] = 'location_id = %d';
            $params[] = (int) $location_id;
        }

        $sql = "SELECT *,
                (no_shows + cancelled_bookings) as problem_count,
                (no_shows + cancelled_bookings) / total_bookings * 100 as problem_rate
                FROM $customer_table
                WHERE " . implode(' AND ', $where) . '
                ORDER BY problem_rate DESC
                LIMIT 20';

        return $this->prepare_and_get_results($sql, $params);
    }

    /**
     * Get customers với filters và sorting
     */
    public function get_customers($args = array()) {
        $customer_table = $this->wpdb->prefix . 'rb_customers';

        $defaults = array(
            'vip_only' => false,
            'blacklisted' => null,
            'search' => '',
            'orderby' => 'total_bookings',
            'order' => 'DESC',
            'limit' => -1,
            'location_id' => null,
        );

        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $params = array();

        if ($args['vip_only']) {
            $where[] = 'vip_status = 1';
        }

        if ($args['blacklisted'] !== null) {
            $where[] = 'blacklisted = %d';
            $params[] = $args['blacklisted'] ? 1 : 0;
        }

        if (!empty($args['search'])) {
            $search = '%' . $this->wpdb->esc_like($args['search']) . '%';
            $where[] = '(name LIKE %s OR phone LIKE %s OR email LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if ($args['location_id']) {
            $where[] = 'location_id = %d';
            $params[] = (int) $args['location_id'];
        }

        $where_sql = implode(' AND ', $where);

        $allowed_orderby = array('total_bookings', 'completed_bookings', 'cancelled_bookings', 'last_visit', 'name', 'created_at');
        $orderby_field = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'total_bookings';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $orderby = sanitize_sql_orderby($orderby_field . ' ' . $order);
        if (!$orderby) {
            $orderby = 'total_bookings DESC';
        }

        $sql = "SELECT * FROM $customer_table WHERE $where_sql ORDER BY $orderby";

        if ($args['limit'] > 0) {
            $sql .= $this->wpdb->prepare(' LIMIT %d', $args['limit']);
        }

        return $this->prepare_and_get_results($sql, $params);
    }

    /**
     * Get customer stats
     */
    public function get_stats($location_id = null) {
        $customer_table = $this->wpdb->prefix . 'rb_customers';
        $where = '1=1';
        $params = array();

        if ($location_id) {
            $where = 'location_id = %d';
            $params[] = (int) $location_id;
        }

        return array(
            'total' => $this->prepare_and_get_var("SELECT COUNT(*) FROM $customer_table WHERE $where", $params),
            'vip' => $this->prepare_and_get_var("SELECT COUNT(*) FROM $customer_table WHERE vip_status = 1 AND $where", $params),
            'blacklisted' => $this->prepare_and_get_var("SELECT COUNT(*) FROM $customer_table WHERE blacklisted = 1 AND $where", $params),
            'new_this_month' => $this->prepare_and_get_var(
                "SELECT COUNT(*) FROM $customer_table WHERE $where AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())",
                $params
            ),
        );
    }

    private function get_booking_with_location($booking_id) {
        $booking_table = $this->wpdb->prefix . 'rb_bookings';

        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM $booking_table WHERE id = %d",
            $booking_id
        ));
    }

    private function prepare_and_get_results($sql, $params = array()) {
        if (!empty($params)) {
            return $this->wpdb->get_results($this->wpdb->prepare($sql, $params));
        }

        return $this->wpdb->get_results($sql);
    }

    private function prepare_and_get_var($sql, $params = array()) {
        if (!empty($params)) {
            return $this->wpdb->get_var($this->wpdb->prepare($sql, $params));
        }

        return $this->wpdb->get_var($sql);
    }
}
