<?php
/**
 * Customer facing booking surfaces - New Design.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Frontend_Public extends RB_Frontend_Base {

    private static $instance = null;

    /**
     * @return self
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    protected function __construct() {
        parent::__construct();
        $this->init_ajax_handlers();
        add_action('init', array($this, 'maybe_handle_email_confirmation'));
    }

    private function init_ajax_handlers() {
        add_action('wp_ajax_rb_submit_booking', array($this, 'handle_booking_submission'));
        add_action('wp_ajax_nopriv_rb_submit_booking', array($this, 'handle_booking_submission'));

        add_action('wp_ajax_rb_check_availability', array($this, 'check_availability'));
        add_action('wp_ajax_nopriv_rb_check_availability', array($this, 'check_availability'));

        add_action('wp_ajax_rb_get_time_slots', array($this, 'get_time_slots'));
        add_action('wp_ajax_nopriv_rb_get_time_slots', array($this, 'get_time_slots'));

        add_action('wp_ajax_rb_get_checkout_time', array($this, 'get_checkout_time'));
        add_action('wp_ajax_nopriv_rb_get_checkout_time', array($this, 'get_checkout_time'));
    }

    public function maybe_handle_email_confirmation() {
        if (!isset($_GET['rb_confirm_token'])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET['rb_confirm_token']));

        global $rb_booking;

        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $result = $rb_booking->confirm_booking_by_token($token);

        $redirect_url = apply_filters('rb_confirmation_redirect_url', home_url('/'));

        if (is_wp_error($result)) {
            $redirect_url = add_query_arg(array(
                'rb_confirmation' => 'error',
                'rb_message' => rawurlencode($result->get_error_message()),
            ), $redirect_url);
        } else {
            $redirect_url = add_query_arg(array(
                'rb_confirmation' => 'success'
            ), $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Render booking form - New single shortcode design
     */
    public function render_booking_form($atts) {
        $atts = shortcode_atts(array(
            'title' => rb_t('book_now'),
            'button_text' => rb_t('book_now'),
            'show_button' => 'yes'
        ), $atts, 'restaurant_booking');

        $locations = $this->get_locations_data();

        if (empty($locations)) {
            return '<div class="rb-alert rb-no-location">' . esc_html__('Please configure at least one restaurant location before displaying the booking form.', 'restaurant-booking') . '</div>';
        }

        $default_location = $locations[0];
        $default_location_id = (int) $default_location['id'];
        $current_language = rb_get_current_language();

        $settings = get_option('rb_settings', array(
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'time_slot_interval' => 30,
            'min_advance_booking' => 2,
            'max_advance_booking' => 30
        ));

        $opening_time = isset($settings['opening_time']) ? $settings['opening_time'] : '09:00';
        $closing_time = isset($settings['closing_time']) ? $settings['closing_time'] : '22:00';
        $time_interval = isset($settings['time_slot_interval']) ? intval($settings['time_slot_interval']) : 30;

        $min_hours = isset($settings['min_advance_booking']) ? intval($settings['min_advance_booking']) : 2;
        $max_days = isset($settings['max_advance_booking']) ? intval($settings['max_advance_booking']) : 30;

        $min_date = date('Y-m-d', strtotime('+' . $min_hours . ' hours'));
        $max_date = date('Y-m-d', strtotime('+' . $max_days . ' days'));

        $time_slots = $this->generate_time_slots($opening_time, $closing_time, $time_interval);

        // Get available languages for switcher
        $available_languages = rb_get_available_languages();
        $languages = array();

        foreach ($available_languages as $locale => $info) {
            $fallback_label = isset($info['name']) ? $info['name'] : $locale;

            switch ($locale) {
                case 'vi_VN':
                    $label = rb_t('language_vietnamese', __('Tiếng Việt', 'restaurant-booking'));
                    break;
                case 'en_US':
                    $label = rb_t('language_english', __('English', 'restaurant-booking'));
                    break;
                case 'ja_JP':
                    $label = rb_t('language_japanese', __('日本語', 'restaurant-booking'));
                    break;
                default:
                    $label = $fallback_label;
                    break;
            }

            if (!empty($info['flag'])) {
                $label = trim($info['flag'] . ' ' . $label);
            }

            $languages[$locale] = $label;
        }

        if (empty($languages)) {
            $languages = array(
                'vi_VN' => '🇻🇳 Tiếng Việt',
                'en_US' => '🇺🇸 English',
                'ja_JP' => '🇯🇵 日本語',
            );
        }

        $show_button = strtolower($atts['show_button']) !== 'no';
        $modal_classes = array('rb-new-modal');
        if (!$show_button) {
            $modal_classes[] = 'rb-new-modal-inline';
            $modal_classes[] = 'show';
        }

        $modal_class_attr = implode(' ', array_map('sanitize_html_class', $modal_classes));
        $modal_aria_hidden = $show_button ? 'true' : 'false';

        $wrapper_classes = array('rb-booking-widget-new');
        if (!$show_button) {
            $wrapper_classes[] = 'rb-booking-widget-inline';
        }
        $wrapper_class_attr = implode(' ', array_map('sanitize_html_class', $wrapper_classes));

        ob_start();
        ?>
        <div class="<?php echo esc_attr($wrapper_class_attr); ?>">
            <?php if (!empty($atts['title'])) : ?>
                <h3 class="rb-new-widget-title"><?php echo esc_html($atts['title']); ?></h3>
            <?php endif; ?>

            <?php if ($show_button) : ?>
                <button type="button" class="rb-new-open-modal-btn">
                    <?php echo esc_html($atts['button_text']); ?>
                </button>
            <?php endif; ?>

            <div id="rb-new-booking-modal" class="<?php echo esc_attr($modal_class_attr); ?>" aria-hidden="<?php echo esc_attr($modal_aria_hidden); ?>" data-inline-mode="<?php echo $show_button ? '0' : '1'; ?>">
                <div class="rb-new-modal-content" role="dialog" aria-modal="true">
                    <button type="button" class="rb-new-close" aria-label="<?php esc_attr_e('Close booking form', 'restaurant-booking'); ?>">&times;</button>

                    <!-- Step 1: Check Availability -->
                    <div class="rb-new-step rb-new-step-availability active" data-step="1">
                        <div class="rb-new-modal-header">
                            <h2><?php echo esc_html(rb_t('check_availability', __('Check Availability', 'restaurant-booking'))); ?></h2>
                            
                            <div class="rb-new-language-switcher">
                                <select id="rb-new-language-select" class="rb-new-lang-select">
                                    <?php foreach ($languages as $code => $label) : ?>
                                        <option value="<?php echo esc_attr($code); ?>"
                                            <?php selected($code, $current_language); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="rb-new-language-status" role="status" aria-live="polite" hidden></p>
                            </div>
                        </div>

                        <form id="rb-new-availability-form" class="rb-new-form" novalidate>
                            <div class="rb-new-form-grid">
                                <div class="rb-new-form-group">
                                    <label for="rb-new-location"><?php echo esc_html(rb_t('location', __('Location', 'restaurant-booking'))); ?></label>
                                    <select id="rb-new-location" name="location_id" required>
                                        <?php foreach ($locations as $location) : ?>
                                            <option value="<?php echo esc_attr($location['id']); ?>"
                                                data-name="<?php echo esc_attr($location['name']); ?>"
                                                data-address="<?php echo esc_attr($location['address']); ?>"
                                                data-hotline="<?php echo esc_attr($location['hotline']); ?>"
                                                data-email="<?php echo esc_attr($location['email']); ?>">
                                                <?php echo esc_html($location['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="rb-new-form-group">
                                    <label for="rb-new-date"><?php echo esc_html(rb_t('booking_date', __('Date', 'restaurant-booking'))); ?></label>
                                    <input type="date" id="rb-new-date" name="booking_date"
                                        min="<?php echo $min_date; ?>"
                                        max="<?php echo $max_date; ?>" required>
                                </div>

                                <div class="rb-new-form-group">
                                    <label for="rb-new-guests"><?php echo esc_html(rb_t('number_of_guests', __('Guests', 'restaurant-booking'))); ?></label>
                                    <select id="rb-new-guests" name="guest_count" required>
                                        <?php for ($i = 1; $i <= 20; $i++) : ?>
                                            <option value="<?php echo $i; ?>"><?php echo $i; ?> <?php echo esc_html(rb_t('people', __('people', 'restaurant-booking'))); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="rb-time-selection-section">
                                <h3><?php echo esc_html(rb_t('choose_time_section_title', __('Select Your Dining Time', 'restaurant-booking'))); ?></h3>

                                <div class="rb-form-row">
                                    <label for="rb-checkin-time"><?php echo esc_html(rb_t('check_in_time', __('Check-in Time', 'restaurant-booking'))); ?> *</label>
                                    <select id="rb-checkin-time" name="checkin_time" required>
                                        <option value=""><?php echo esc_html(rb_t('select_time_hint', __('-- Select time --', 'restaurant-booking'))); ?></option>
                                        <?php foreach ($time_slots as $slot) : ?>
                                            <option value="<?php echo esc_attr($slot); ?>"><?php echo esc_html($slot); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="rb-hint"><?php echo esc_html(rb_t('early_late_note', __('Customers can arrive 15 minutes early or late', 'restaurant-booking'))); ?></small>
                                </div>

                                <div class="rb-form-row">
                                    <label for="rb-duration"><?php echo esc_html(rb_t('duration', __('Duration', 'restaurant-booking'))); ?> *</label>
                                    <select id="rb-duration" name="duration" required>
                                        <option value=""><?php echo esc_html(rb_t('select_duration_hint', __('-- Select duration --', 'restaurant-booking'))); ?></option>
                                        <option value="1"><?php echo esc_html(rb_t('duration_1h', __('1 hour', 'restaurant-booking'))); ?></option>
                                        <option value="1.5"><?php echo esc_html(rb_t('duration_1_5h', __('1.5 hours', 'restaurant-booking'))); ?></option>
                                        <option value="2"><?php echo esc_html(rb_t('duration_2h', __('2 hours (Recommended)', 'restaurant-booking'))); ?></option>
                                        <option value="2.5"><?php echo esc_html(rb_t('duration_2_5h', __('2.5 hours', 'restaurant-booking'))); ?></option>
                                        <option value="3"><?php echo esc_html(rb_t('duration_3h', __('3 hours', 'restaurant-booking'))); ?></option>
                                        <option value="3.5"><?php echo esc_html(rb_t('duration_3_5h', __('3.5 hours', 'restaurant-booking'))); ?></option>
                                        <option value="4"><?php echo esc_html(rb_t('duration_4h', __('4 hours', 'restaurant-booking'))); ?></option>
                                        <option value="5"><?php echo esc_html(rb_t('duration_5h', __('5 hours', 'restaurant-booking'))); ?></option>
                                        <option value="6"><?php echo esc_html(rb_t('duration_6h', __('6 hours', 'restaurant-booking'))); ?></option>
                                    </select>
                                </div>

                                <div class="rb-time-display">
                                    <p>
                                        <strong><?php echo esc_html(rb_t('booking_stats', __('Booking Summary', 'restaurant-booking'))); ?>:</strong><br>
                                        <?php echo esc_html(rb_t('check_in_time_label', __('Check-in time', 'restaurant-booking'))); ?>: <span id="rb-display-checkin">--:--</span><br>
                                        <?php echo esc_html(rb_t('check_out_time_label', __('Check-out time', 'restaurant-booking'))); ?>: <span id="rb-display-checkout">--:--</span><br>
                                        <?php echo esc_html(rb_t('table_ready_time', __('Table will be ready at', 'restaurant-booking'))); ?>: <span id="rb-display-cleanup">--:--</span>
                                    </p>
                                </div>
                            </div>

                            <div id="rb-availability-message" class="rb-availability-message" hidden></div>

                            <div class="rb-new-form-actions">
                                <button type="button" id="rb-check-availability" class="rb-new-btn-primary">
                                    <?php echo esc_html(rb_t('check_availability', __('Check Availability', 'restaurant-booking'))); ?>
                                </button>
                            </div>

                        </form>
                    </div>

                    <!-- Step 2: Booking Details -->
                    <div class="rb-new-step rb-new-step-details" data-step="2" hidden>
                        <div class="rb-new-modal-header">
                            <h2><?php echo esc_html(rb_t('booking_details', __('Booking Details', 'restaurant-booking'))); ?></h2>
                            
                            <div class="rb-new-language-switcher">
                                <select class="rb-new-lang-select rb-new-lang-select-step2">
                                    <?php foreach ($languages as $code => $label) : ?>
                                        <option value="<?php echo esc_attr($code); ?>"
                                            <?php selected($code, $current_language); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="rb-new-language-status" role="status" aria-live="polite" hidden></p>
                            </div>
                        </div>

                        <div class="rb-new-booking-summary">
                            <h3><?php echo esc_html(rb_t('reservation_summary', __('Reservation Summary', 'restaurant-booking'))); ?></h3>
                            <div class="rb-new-summary-content">
                                <p><strong><?php echo esc_html(rb_t('location', __('Location', 'restaurant-booking'))); ?>:</strong> <span id="rb-new-summary-location"></span></p>
                                <p><strong><?php echo esc_html(rb_t('booking_date', __('Booking Date', 'restaurant-booking'))); ?>:</strong> <span id="rb-new-summary-date"></span></p>
                                <p><strong><?php echo esc_html(rb_t('check_in_time_label', __('Check-in time', 'restaurant-booking'))); ?>:</strong> <span id="rb-new-summary-checkin"></span></p>
                                <p><strong><?php echo esc_html(rb_t('check_out_time_label', __('Check-out time', 'restaurant-booking'))); ?>:</strong> <span id="rb-new-summary-checkout"></span></p>
                                <p><strong><?php echo esc_html(rb_t('table_ready_time', __('Table will be ready at', 'restaurant-booking'))); ?>:</strong> <span id="rb-new-summary-cleanup"></span></p>
                                <p><strong><?php echo esc_html(rb_t('guests', __('Guests', 'restaurant-booking'))); ?>:</strong> <span id="rb-new-summary-guests"></span></p>
                            </div>
                        </div>

                        <form id="rb-new-booking-form" class="rb-new-form">
                            <?php wp_nonce_field('rb_booking_nonce', 'rb_nonce'); ?>
                            <input type="hidden" name="location_id" id="rb-new-hidden-location">
                            <input type="hidden" name="booking_date" id="rb-new-hidden-date">
                            <input type="hidden" name="booking_time" id="rb-new-hidden-time">
                            <input type="hidden" name="checkin_time" id="rb-new-hidden-checkin">
                            <input type="hidden" name="checkout_time" id="rb-new-hidden-checkout">
                            <input type="hidden" name="duration" id="rb-new-hidden-duration">
                            <input type="hidden" name="guest_count" id="rb-new-hidden-guests">
                            <input type="hidden" name="language" id="rb-new-hidden-language" value="<?php echo esc_attr($current_language); ?>">

                            <div class="rb-new-form-section">
                                <h3 class="rb-new-section-title"><?php echo esc_html(rb_t('contact_information', __('Contact Information', 'restaurant-booking'))); ?></h3>
                                
                                <div class="rb-new-form-grid">
                                    <div class="rb-new-form-group">
                                        <label for="rb-new-customer-name"><?php echo esc_html(rb_t('full_name', __('Full Name', 'restaurant-booking'))); ?> *</label>
                                        <input type="text" id="rb-new-customer-name" name="customer_name" required>
                                    </div>

                                    <div class="rb-new-form-group">
                                        <label for="rb-new-customer-phone"><?php echo esc_html(rb_t('phone_number', __('Phone Number', 'restaurant-booking'))); ?> *</label>
                                        <input type="tel" id="rb-new-customer-phone" name="customer_phone" required>
                                    </div>

                                    <div class="rb-new-form-group rb-new-form-group-wide">
                                        <label for="rb-new-customer-email"><?php echo esc_html(rb_t('email', __('Email', 'restaurant-booking'))); ?> *</label>
                                        <input type="email" id="rb-new-customer-email" name="customer_email" required>
                                        <small class="rb-new-email-note">
                                            <?php echo esc_html(rb_t('confirmation_email_note', __('A confirmation link will be sent to this email.', 'restaurant-booking'))); ?>
                                        </small>
                                    </div>

                                    <div class="rb-new-form-group rb-new-form-group-wide">
                                        <label for="rb-new-special-requests"><?php echo esc_html(rb_t('special_requests', __('Special Requests', 'restaurant-booking'))); ?></label>
                                        <textarea id="rb-new-special-requests" name="special_requests" rows="3" placeholder="<?php esc_attr_e('Any special requests or dietary requirements?', 'restaurant-booking'); ?>"></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="rb-new-form-actions">
                                <button type="button" class="rb-new-btn-secondary" id="rb-new-back-btn">
                                    <?php echo esc_html(rb_t('back', __('Back', 'restaurant-booking'))); ?>
                                </button>
                                <button type="submit" class="rb-new-btn-primary">
                                    <?php echo esc_html(rb_t('confirm_booking', __('Confirm Booking', 'restaurant-booking'))); ?>
                                </button>
                            </div>

                            <div id="rb-new-booking-result" class="rb-new-result" hidden></div>
                        </form>
                    </div>
                </div>
            </div>

        <?php
        return ob_get_clean();
    }

    public function get_location_time_slots($location_id) {
        $location_id = (int) $location_id;

        if ($location_id <= 0) {
            return array();
        }

        global $rb_location;

        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $settings = $rb_location->get_settings($location_id);

        if (empty($settings)) {
            $settings = get_option('rb_settings', array());
        }

        $opening_time = isset($settings['opening_time']) ? $settings['opening_time'] : null;
        $closing_time = isset($settings['closing_time']) ? $settings['closing_time'] : null;
        $interval = isset($settings['time_slot_interval']) ? intval($settings['time_slot_interval']) : null;

        return $this->generate_time_slots($opening_time, $closing_time, $interval);
    }

    public function get_checkout_time() {
        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
        }

        $checkin_time = isset($_POST['checkin_time']) ? sanitize_text_field(wp_unslash($_POST['checkin_time'])) : '';
        $duration = isset($_POST['duration']) ? floatval($_POST['duration']) : 0;

        if (empty($checkin_time) || $duration <= 0) {
            wp_send_json_error(array('message' => __('Invalid request parameters.', 'restaurant-booking')));
        }

        if (strpos($checkin_time, ':') === false) {
            wp_send_json_error(array('message' => __('Invalid check-in time format.', 'restaurant-booking')));
        }

        list($hour, $minute) = array_map('intval', explode(':', $checkin_time));

        $minutes = ($hour * 60) + $minute + intval(round($duration * 60));
        $checkout_hour = floor($minutes / 60) % 24;
        $checkout_minute = $minutes % 60;
        $checkout_time = sprintf('%02d:%02d', $checkout_hour, $checkout_minute);

        $cleanup_timestamp = strtotime($checkout_time);
        if (false === $cleanup_timestamp) {
            $cleanup_timestamp = strtotime('today') + ($checkout_hour * HOUR_IN_SECONDS) + ($checkout_minute * MINUTE_IN_SECONDS);
        }

        $cleanup_end = date('H:i', $cleanup_timestamp + (15 * MINUTE_IN_SECONDS) + HOUR_IN_SECONDS);

        wp_send_json_success(array(
            'checkout_time' => $checkout_time,
            'cleanup_end' => $cleanup_end,
        ));
    }

    public function get_alternative_times($location_id, $date, $checkin_time, $duration, $guest_count) {
        global $rb_booking;

        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $offsets = array(-90, -60, -30, 30, 60, 90);
        $alternatives = array();

        foreach ($offsets as $offset) {
            $alt_timestamp = strtotime($checkin_time) + ($offset * MINUTE_IN_SECONDS);

            if ($alt_timestamp === false) {
                continue;
            }

            $alt_checkin = date('H:i', $alt_timestamp);
            $alt_checkout = date('H:i', strtotime($alt_checkin) + ($duration * HOUR_IN_SECONDS));

            if ($rb_booking->is_time_slot_available($date, $alt_checkin, $guest_count, $alt_checkin, $alt_checkout, null, $location_id)) {
                $alternatives[] = array(
                    'checkin' => $alt_checkin,
                    'checkout' => $alt_checkout,
                    'diff_minutes' => $offset,
                );
            }

            if (count($alternatives) >= 2) {
                break;
            }
        }

        return $alternatives;
    }

    // Keep existing methods for backward compatibility and AJAX handlers
    public function render_multi_location_portal($atts) {
        // Redirect to new booking form
        return $this->render_booking_form($atts);
    }

    // Keep all existing AJAX methods unchanged
    public function handle_booking_submission() {
        $nonce = isset($_POST['rb_nonce']) ? $_POST['rb_nonce'] : (isset($_POST['rb_nonce_inline']) ? $_POST['rb_nonce_inline'] : (isset($_POST['rb_nonce_portal']) ? $_POST['rb_nonce_portal'] : ''));
        if (!wp_verify_nonce($nonce, 'rb_booking_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        if (!$location_id) {
            wp_send_json_error(array('message' => __('Please choose a location before submitting.', 'restaurant-booking')));
            wp_die();
        }

        $location = $this->get_location_details($location_id);
        if (empty($location)) {
            wp_send_json_error(array('message' => __('Selected location is not available.', 'restaurant-booking')));
            wp_die();
        }

        $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : rb_get_current_language();

        $required_fields = array('customer_name', 'customer_phone', 'customer_email', 'guest_count', 'booking_date', 'booking_time');

        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                wp_send_json_error(array('message' => __('Please fill in all required fields.', 'restaurant-booking')));
                wp_die();
            }
        }

        $email = sanitize_email($_POST['customer_email']);
        if (empty($email) || !is_email($email)) {
            $hotline_message = !empty($location['hotline']) ? sprintf(__('Please call %s to complete your reservation.', 'restaurant-booking'), $location['hotline']) : __('Please contact the restaurant directly to book.', 'restaurant-booking');
            wp_send_json_error(array('message' => $hotline_message));
            wp_die();
        }

        $phone = sanitize_text_field($_POST['customer_phone']);
        if (!preg_match('/^[0-9+\-\s]{8,20}$/', $phone)) {
            wp_send_json_error(array('message' => __('Please enter a valid phone number.', 'restaurant-booking')));
            wp_die();
        }

        $guest_count = intval($_POST['guest_count']);
        if ($guest_count <= 0) {
            wp_send_json_error(array('message' => __('Please select a valid number of guests.', 'restaurant-booking')));
            wp_die();
        }

        $booking_date_raw = sanitize_text_field($_POST['booking_date']);
        $checkin_time = isset($_POST['checkin_time']) ? sanitize_text_field($_POST['checkin_time']) : sanitize_text_field($_POST['booking_time']);
        $checkout_time = isset($_POST['checkout_time']) ? sanitize_text_field($_POST['checkout_time']) : '';
        $duration_hours = isset($_POST['duration']) ? floatval($_POST['duration']) : 0;

        if (!$this->is_booking_allowed_on_date($booking_date_raw, $location_id)) {
            wp_send_json_error(array('message' => __('This date is not available for reservations. Please choose another day.', 'restaurant-booking')));
            wp_die();
        }

        $booking_date = date('Y-m-d', strtotime($booking_date_raw));
        if (!$booking_date || !$checkin_time) {
            wp_send_json_error(array('message' => __('Please choose a valid booking date and time.', 'restaurant-booking')));
            wp_die();
        }

        if (empty($checkout_time)) {
            $checkout_time = date('H:i', strtotime($checkin_time) + (2 * HOUR_IN_SECONDS));
        }

        global $rb_booking;
        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $is_available = $rb_booking->is_time_slot_available($booking_date, $checkin_time, $guest_count, $checkin_time, $checkout_time, null, $location_id);

        if (!$is_available) {
            $suggestions = $this->get_alternative_times($location_id, $booking_date, $checkin_time, $duration_hours > 0 ? $duration_hours : 2, $guest_count);

            $message = sprintf(
                __('No availability for %1$s at %2$s. Please choose another time.', 'restaurant-booking'),
                $booking_date,
                $checkin_time
            );

            wp_send_json_error(array(
                'message' => $message,
                'suggestions' => $suggestions
            ));
            wp_die();
        }

        $booking_data = array(
            'customer_name' => sanitize_text_field($_POST['customer_name']),
            'customer_phone' => $phone,
            'customer_email' => $email,
            'guest_count' => $guest_count,
            'booking_date' => $booking_date_raw,
            'booking_time' => $checkin_time,
            'checkin_time' => $checkin_time,
            'checkout_time' => $checkout_time,
            'special_requests' => isset($_POST['special_requests']) ? sanitize_textarea_field($_POST['special_requests']) : '',
            'status' => 'pending',
            'booking_source' => 'website',
            'created_at' => current_time('mysql'),
            'location_id' => $location_id,
            'language' => $language
        );

        $booking_id = $rb_booking->create_booking($booking_data);

        if (is_wp_error($booking_id)) {
            wp_send_json_error(array('message' => $booking_id->get_error_message()));
            wp_die();
        }

        $booking = $rb_booking->get_booking($booking_id);

        if ($booking && class_exists('RB_Email')) {
            $email_handler = new RB_Email();
            $email_handler->send_admin_notification($booking);
            $email_handler->send_pending_confirmation($booking, $location);
        }

        $success_message = sprintf(
            __('Thank you %1$s! We have sent a confirmation email to %2$s. Please click the link to secure your table at %3$s. For urgent assistance call %4$s.', 'restaurant-booking'),
            $booking_data['customer_name'],
            $booking_data['customer_email'],
            $location['name'],
            !empty($location['hotline']) ? $location['hotline'] : __('the restaurant hotline', 'restaurant-booking')
        );

        wp_send_json_success(array(
            'message' => $success_message,
            'booking_id' => $booking_id
        ));

        wp_die();
    }

    public function check_availability() {
        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $checkin_time = isset($_POST['checkin_time']) ? sanitize_text_field($_POST['checkin_time']) : '';
        $checkout_time = isset($_POST['checkout_time']) ? sanitize_text_field($_POST['checkout_time']) : '';
        $duration = isset($_POST['duration']) ? floatval($_POST['duration']) : 0;
        $guests = isset($_POST['guests']) ? intval($_POST['guests']) : 0;
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if (empty($date) || empty($checkin_time) || empty($checkout_time) || $guests <= 0 || !$location_id) {
            wp_send_json_error(array('message' => __('Missing data. Please select location, date, time and number of guests.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->is_booking_allowed_on_date($date, $location_id)) {
            wp_send_json_error(array('message' => __('This date is not available for reservations. Please choose another day.', 'restaurant-booking')));
            wp_die();
        }

        global $rb_booking;
        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $is_available = $rb_booking->is_time_slot_available($date, $checkin_time, $guests, $checkin_time, $checkout_time, null, $location_id);
        $count = $rb_booking->available_table_count($date, $checkin_time, $guests, $location_id, $checkin_time, $checkout_time);
        $cleanup_time = date('H:i', strtotime($checkout_time) + (15 * MINUTE_IN_SECONDS) + HOUR_IN_SECONDS);

        if ($is_available && $count > 0) {
            wp_send_json_success(array(
                'available' => true,
                'message' => sprintf(__('We have %1$d tables available for %2$d guests.', 'restaurant-booking'), $count, $guests),
                'available_tables' => $count,
                'checkin_time' => $checkin_time,
                'checkout_time' => $checkout_time,
                'cleanup_time' => $cleanup_time
            ));
        } else {
            $alternatives = $this->get_alternative_times($location_id, $date, $checkin_time, $duration > 0 ? $duration : 2, $guests);

            wp_send_json_success(array(
                'available' => false,
                'message' => __('No availability for the selected time. Please consider one of the suggested slots.', 'restaurant-booking'),
                'alternatives' => $alternatives,
            ));
        }

        wp_die();
    }

    public function get_time_slots() {
        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $guest_count = isset($_POST['guest_count']) ? intval($_POST['guest_count']) : 0;
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if (empty($date) || $guest_count <= 0 || !$location_id) {
            wp_send_json_error(array('message' => __('Missing data. Please select date, guests and location.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->is_booking_allowed_on_date($date, $location_id)) {
            wp_send_json_success(array('slots' => array()));
            wp_die();
        }

        global $rb_booking;

        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $time_slots = $this->get_location_time_slots($location_id);
        $available_slots = array();
        $current_timestamp = current_time('timestamp');

        foreach ($time_slots as $slot) {
            $slot_timestamp = strtotime($date . ' ' . $slot);

            if (!$slot_timestamp || $slot_timestamp <= $current_timestamp) {
                continue;
            }

            if ($rb_booking->is_time_slot_available($date, $slot, $guest_count, $slot, date('H:i', strtotime($slot) + (2 * HOUR_IN_SECONDS)), null, $location_id)) {
                $available_slots[] = $slot;
            }
        }

        wp_send_json_success(array('slots' => array_values(array_unique($available_slots))));

        wp_die();
    }
}
