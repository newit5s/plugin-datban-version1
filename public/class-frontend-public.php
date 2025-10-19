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

                        <form id="rb-new-availability-form" class="rb-new-form">
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
                                    <label for="rb-new-time"><?php echo esc_html(rb_t('booking_time', __('Time', 'restaurant-booking'))); ?></label>
                                    <select id="rb-new-time" name="booking_time" required>
                                        <option value=""><?php echo esc_html(rb_t('select_time', __('Select time', 'restaurant-booking'))); ?></option>
                                        <?php if (!empty($time_slots)) : ?>
                                            <?php foreach ($time_slots as $slot) : ?>
                                                <option value="<?php echo esc_attr($slot); ?>"><?php echo esc_html($slot); ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
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

                            <div class="rb-new-form-actions">
                                <button type="submit" class="rb-new-btn-primary">
                                    <?php echo esc_html(rb_t('check_availability', __('Check Availability', 'restaurant-booking'))); ?>
                                </button>
                            </div>

                            <div id="rb-new-availability-result" class="rb-new-result" hidden></div>
                            
                            <div id="rb-new-suggestions" class="rb-new-suggestions" hidden>
                                <h4><?php echo esc_html(rb_t('suggested_times', __('Suggested Times', 'restaurant-booking'))); ?></h4>
                                <div class="rb-new-suggestion-list"></div>
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
                                <p><strong><?php echo esc_html(rb_t('date_time', __('Date & Time', 'restaurant-booking'))); ?>:</strong> <span id="rb-new-summary-datetime"></span></p>
                                <p><strong><?php echo esc_html(rb_t('guests', __('Guests', 'restaurant-booking'))); ?>:</strong> <span id="rb-new-summary-guests"></span></p>
                            </div>
                        </div>

                        <form id="rb-new-booking-form" class="rb-new-form">
                            <?php wp_nonce_field('rb_booking_nonce', 'rb_nonce'); ?>
                            <input type="hidden" name="location_id" id="rb-new-hidden-location">
                            <input type="hidden" name="booking_date" id="rb-new-hidden-date">
                            <input type="hidden" name="booking_time" id="rb-new-hidden-time">
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
        $booking_time = sanitize_text_field($_POST['booking_time']);

        if (!$this->is_booking_allowed_on_date($booking_date_raw, $location_id)) {
            wp_send_json_error(array('message' => __('This date is not available for reservations. Please choose another day.', 'restaurant-booking')));
            wp_die();
        }

        $booking_date = date('Y-m-d', strtotime($booking_date_raw));
        if (!$booking_date || !$booking_time) {
            wp_send_json_error(array('message' => __('Please choose a valid booking date and time.', 'restaurant-booking')));
            wp_die();
        }

        global $rb_booking;
        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $is_available = $rb_booking->is_time_slot_available($booking_date, $booking_time, $guest_count, null, $location_id);

        if (!$is_available) {
            $suggestions = $rb_booking->suggest_time_slots(
                $location_id,
                $booking_date,
                $booking_time,
                $guest_count,
                30
            );

            $message = sprintf(
                __('No availability for %1$s at %2$s. Please choose another time.', 'restaurant-booking'),
                $booking_date,
                $booking_time
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
            'booking_time' => $booking_time,
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
        $time = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '';
        $guests = isset($_POST['guests']) ? intval($_POST['guests']) : 0;
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if (empty($date) || empty($time) || $guests <= 0 || !$location_id) {
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

        $is_available = $rb_booking->is_time_slot_available($date, $time, $guests, null, $location_id);
        $count = $rb_booking->available_table_count($date, $time, $guests, $location_id);

        if ($is_available && $count > 0) {
            $message = sprintf(__('We have %1$d tables available for %2$d guests.', 'restaurant-booking'), $count, $guests);
            wp_send_json_success(array(
                'available' => true,
                'message' => $message,
                'count' => $count
            ));
        } else {
            $suggestions = $rb_booking->suggest_time_slots($location_id, $date, $time, $guests, 30);
            $message = __('No availability for the selected time. Please consider one of the suggested slots.', 'restaurant-booking');

            wp_send_json_success(array(
                'available' => false,
                'message' => $message,
                'suggestions' => $suggestions
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

        $settings = array();

        if ($this->location_helper) {
            $settings = $this->location_helper->get_settings($location_id);
        }

        if (empty($settings)) {
            $settings = get_option('rb_settings', array());
        }

        $opening_time = isset($settings['opening_time']) ? $settings['opening_time'] : null;
        $closing_time = isset($settings['closing_time']) ? $settings['closing_time'] : null;
        $interval = isset($settings['time_slot_interval']) ? intval($settings['time_slot_interval']) : null;

        $time_slots = $this->generate_time_slots($opening_time, $closing_time, $interval);
        $available_slots = array();
        $current_timestamp = current_time('timestamp');

        foreach ($time_slots as $slot) {
            $slot_timestamp = strtotime($date . ' ' . $slot);

            if (!$slot_timestamp || $slot_timestamp <= $current_timestamp) {
                continue;
            }

            if ($rb_booking->is_time_slot_available($date, $slot, $guest_count, null, $location_id)) {
                $available_slots[] = $slot;
            }
        }

        wp_send_json_success(array('slots' => array_values(array_unique($available_slots))));

        wp_die();
    }
}
