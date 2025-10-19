<?php
/**
 * Shared functionality for frontend surfaces.
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class RB_Frontend_Base {

    /**
     * @var RB_Location
     */
    protected $location_helper;

    protected function __construct() {
        $this->init_location_helper();
    }

    protected function init_location_helper() {
        global $rb_location;

        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $this->location_helper = $rb_location;
    }

    protected function get_locations_data() {
        if (!$this->location_helper) {
            return array();
        }

        $locations = $this->location_helper->all();
        $data = array();

        foreach ($locations as $location) {
            $data[] = array(
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

        return $data;
    }

    protected function get_location_details($location_id) {
        if (!$this->location_helper) {
            return array();
        }

        $location = $this->location_helper->get($location_id);

        if (!$location) {
            return array();
        }

        return array(
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

    protected function generate_time_slots($start = null, $end = null, $interval = null) {
        $settings = get_option('rb_settings', array());

        $mode = isset($settings['working_hours_mode']) ? $settings['working_hours_mode'] : 'simple';
        $interval = $interval ?: (isset($settings['time_slot_interval']) ? intval($settings['time_slot_interval']) : 30);
        $buffer = isset($settings['booking_buffer_time']) ? intval($settings['booking_buffer_time']) : 0;

        $slots = array();

        if ($mode === 'advanced') {
            $morning_start = isset($settings['morning_shift_start']) ? $settings['morning_shift_start'] : '09:00';
            $morning_end = isset($settings['morning_shift_end']) ? $settings['morning_shift_end'] : '14:00';
            $evening_start = isset($settings['evening_shift_start']) ? $settings['evening_shift_start'] : '17:00';
            $evening_end = isset($settings['evening_shift_end']) ? $settings['evening_shift_end'] : '22:00';

            $slots = array_merge($slots, $this->generate_shift_slots($morning_start, $morning_end, $interval, $buffer));
            $slots = array_merge($slots, $this->generate_shift_slots($evening_start, $evening_end, $interval, $buffer));
        } else {
            $start = $start ?: (isset($settings['opening_time']) ? $settings['opening_time'] : '09:00');
            $end = $end ?: (isset($settings['closing_time']) ? $settings['closing_time'] : '22:00');

            $has_lunch_break = isset($settings['lunch_break_enabled']) && $settings['lunch_break_enabled'] === 'yes';

            if ($has_lunch_break) {
                $lunch_start = isset($settings['lunch_break_start']) ? $settings['lunch_break_start'] : '14:00';
                $lunch_end = isset($settings['lunch_break_end']) ? $settings['lunch_break_end'] : '17:00';

                $slots = array_merge($slots, $this->generate_shift_slots($start, $lunch_start, $interval, $buffer));
                $slots = array_merge($slots, $this->generate_shift_slots($lunch_end, $end, $interval, $buffer));
            } else {
                $slots = $this->generate_shift_slots($start, $end, $interval, $buffer);
            }
        }

        return $slots;
    }

    protected function generate_shift_slots($start, $end, $interval, $buffer = 0) {
        $slots = array();
        $start_time = strtotime($start);
        $end_time = strtotime($end);
        $step = ($interval + $buffer) * MINUTE_IN_SECONDS;

        while ($start_time < $end_time) {
            $slots[] = date('H:i', $start_time);
            $start_time += $step;
        }

        return $slots;
    }

    protected function is_booking_allowed_on_date($date, $location_id = null) {
        $settings = array();

        if ($location_id && $this->location_helper) {
            $settings = $this->location_helper->get_settings($location_id);
        }

        if (empty($settings)) {
            $settings = get_option('rb_settings', array());
        }

        $closed_dates = isset($settings['special_closed_dates']) ? $settings['special_closed_dates'] : '';
        if (!empty($closed_dates)) {
            $dates_array = array_map('trim', explode("\n", $closed_dates));
            if (in_array($date, $dates_array, true)) {
                return false;
            }
        }

        $weekend_enabled = isset($settings['weekend_enabled']) && $settings['weekend_enabled'] === 'yes';
        $day_of_week = date('N', strtotime($date));

        if (!$weekend_enabled && ($day_of_week == 6 || $day_of_week == 7)) {
            return false;
        }

        $min_advance = isset($settings['min_advance_booking']) ? intval($settings['min_advance_booking']) : 2;
        $max_advance = isset($settings['max_advance_booking']) ? intval($settings['max_advance_booking']) : 30;

        $booking_timestamp = strtotime($date);
        $now = current_time('timestamp');
        $min_timestamp = $now + ($min_advance * HOUR_IN_SECONDS);
        $max_timestamp = $now + ($max_advance * DAY_IN_SECONDS);

        if ($booking_timestamp < $min_timestamp || $booking_timestamp > $max_timestamp) {
            return false;
        }

        return true;
    }
}
