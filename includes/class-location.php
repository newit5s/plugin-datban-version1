<?php
/**
 * Location helper class
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Location {
    /** @var wpdb */
    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function all($args = array()) {
        $defaults = array(
            'orderby' => 'name',
            'order' => 'ASC',
        );

        $args = wp_parse_args($args, $defaults);

        $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';
        $orderby = in_array($args['orderby'], array('id', 'name', 'slug'), true) ? $args['orderby'] : 'name';

        $table = $this->wpdb->prefix . 'rb_locations';

        return $this->wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY {$orderby} {$order}"
        );
    }

    public function get($id) {
        $table = $this->wpdb->prefix . 'rb_locations';

        return $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
        );
    }

    public function get_by_slug($slug) {
        $table = $this->wpdb->prefix . 'rb_locations';

        return $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", $slug)
        );
    }

    public function get_settings($id) {
        $location = $this->get($id);

        if (!$location) {
            return array();
        }

        $global = get_option('rb_settings', array());
        $defaults = array(
            'working_hours_mode' => isset($global['working_hours_mode']) ? $global['working_hours_mode'] : 'simple',
            'lunch_break_enabled' => isset($global['lunch_break_enabled']) ? $global['lunch_break_enabled'] : 'no',
            'lunch_break_start' => isset($global['lunch_break_start']) ? $global['lunch_break_start'] : '14:00',
            'lunch_break_end' => isset($global['lunch_break_end']) ? $global['lunch_break_end'] : '17:00',
            'morning_shift_start' => isset($global['morning_shift_start']) ? $global['morning_shift_start'] : '09:00',
            'morning_shift_end' => isset($global['morning_shift_end']) ? $global['morning_shift_end'] : '14:00',
            'evening_shift_start' => isset($global['evening_shift_start']) ? $global['evening_shift_start'] : '17:00',
            'evening_shift_end' => isset($global['evening_shift_end']) ? $global['evening_shift_end'] : '22:00',
            'time_slot_interval' => isset($global['time_slot_interval']) ? (int) $global['time_slot_interval'] : 30,
            'booking_buffer_time' => isset($global['booking_buffer_time']) ? (int) $global['booking_buffer_time'] : 0,
            'min_advance_booking' => isset($global['min_advance_booking']) ? (int) $global['min_advance_booking'] : 2,
            'max_advance_booking' => isset($global['max_advance_booking']) ? (int) $global['max_advance_booking'] : 30,
            'max_guests_per_booking' => isset($global['max_guests_per_booking']) ? (int) $global['max_guests_per_booking'] : 20,
            'auto_confirm_enabled' => isset($global['auto_confirm_enabled']) ? $global['auto_confirm_enabled'] : 'no',
            'require_deposit' => isset($global['require_deposit']) ? $global['require_deposit'] : 'no',
            'deposit_amount' => isset($global['deposit_amount']) ? (int) $global['deposit_amount'] : 0,
            'deposit_for_guests' => isset($global['deposit_for_guests']) ? (int) $global['deposit_for_guests'] : 0,
            'admin_email' => isset($global['admin_email']) ? $global['admin_email'] : get_option('admin_email'),
            'enable_email' => isset($global['enable_email']) ? $global['enable_email'] : 'yes',
            'enable_sms' => isset($global['enable_sms']) ? $global['enable_sms'] : 'no',
            'sms_api_key' => isset($global['sms_api_key']) ? $global['sms_api_key'] : '',
            'reminder_hours_before' => isset($global['reminder_hours_before']) ? (int) $global['reminder_hours_before'] : 24,
            'special_closed_dates' => isset($global['special_closed_dates']) ? $global['special_closed_dates'] : '',
            'cancellation_hours' => isset($global['cancellation_hours']) ? (int) $global['cancellation_hours'] : 2,
            'weekend_enabled' => isset($global['weekend_enabled']) ? $global['weekend_enabled'] : 'yes',
            'no_show_auto_blacklist' => isset($global['no_show_auto_blacklist']) ? (int) $global['no_show_auto_blacklist'] : 3,
        );

        $option_key = 'rb_location_settings_' . (int) $id;
        $extra = get_option($option_key, array());
        if (!is_array($extra)) {
            $extra = array();
        }

        $settings = wp_parse_args($extra, $defaults);

        $settings['opening_time'] = $location->opening_time;
        $settings['closing_time'] = $location->closing_time;
        $settings['time_slot_interval'] = (int) $location->time_slot_interval;
        $settings['min_advance_booking'] = (int) $location->min_advance_booking;
        $settings['max_advance_booking'] = (int) $location->max_advance_booking;
        $settings['languages'] = array_map('trim', explode(',', $location->languages));
        $settings['hotline'] = $location->hotline;
        $settings['email'] = $location->email;
        $settings['address'] = $location->address;
        $settings['shift_notes'] = $location->shift_notes;
        $settings['default_table_count'] = (int) $location->default_table_count;
        $settings['default_capacity'] = (int) $location->default_capacity;

        return $settings;
    }

    public function update_settings($id, $data) {
        $table = $this->wpdb->prefix . 'rb_locations';

        $column_keys = array(
            'name',
            'email',
            'hotline',
            'address',
            'opening_time',
            'closing_time',
            'time_slot_interval',
            'min_advance_booking',
            'max_advance_booking',
            'default_table_count',
            'default_capacity',
            'shift_notes',
            'languages',
        );

        $column_map = array_fill_keys($column_keys, true);
        $fields = array_intersect_key($data, $column_map);

        if (isset($fields['languages'])) {
            if (is_array($fields['languages'])) {
                $fields['languages'] = implode(',', array_map('trim', $fields['languages']));
            } else {
                $fields['languages'] = sanitize_text_field($fields['languages']);
            }
        }

        $updated_table = true;
        if (!empty($fields)) {
            $updated_table = $this->wpdb->update(
                $table,
                $fields,
                array('id' => (int) $id)
            );
        }

        if ($updated_table === false) {
            return false;
        }

        $extra = array_diff_key($data, $column_map);
        if (!empty($extra)) {
            $option_key = 'rb_location_settings_' . (int) $id;
            $stored = get_option($option_key, array());
            if (!is_array($stored)) {
                $stored = array();
            }

            $merged = array_merge($stored, $extra);
            update_option($option_key, $merged);
        }

        return true;
    }
}
