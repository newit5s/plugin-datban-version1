<?php
/**
 * Enqueue scripts and styles for the modern booking interface.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Assets_Manager {

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Cache the result of should_enqueue_assets to avoid duplicate checks.
     *
     * @var bool|null
     */
    private $should_enqueue = null;

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('wp_head', array($this, 'print_inline_styles'));
    }

    /**
     * Enqueue CSS/JS for the booking widget.
     */
    public function enqueue_frontend_assets() {
        if (!$this->should_enqueue_assets()) {
            return;
        }

        $style_path = RB_PLUGIN_DIR . 'assets/css/new-frontend.css';
        $style_version = file_exists($style_path) ? filemtime($style_path) : RB_VERSION;
        wp_enqueue_style(
            'rb-new-frontend',
            RB_PLUGIN_URL . 'assets/css/new-frontend.css',
            array(),
            $style_version
        );

        $script_path = RB_PLUGIN_DIR . 'assets/js/new-booking.js';
        $script_version = file_exists($script_path) ? filemtime($script_path) : RB_VERSION;
        wp_enqueue_script(
            'rb-new-booking',
            RB_PLUGIN_URL . 'assets/js/new-booking.js',
            array('jquery'),
            $script_version,
            true
        );

        wp_localize_script('rb-new-booking', 'rbBookingAjax', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rb_frontend_nonce'),
            'languageNonce' => wp_create_nonce('rb_language_nonce'),
            'languageAction' => 'rb_switch_language',
            'shouldReloadOnLanguageChange' => true,
            'currentLanguage' => rb_get_current_language(),
        ));

        wp_localize_script('rb-new-booking', 'rbBookingStrings', array(
            'checking' => rb_t('checking', __('Checking...', 'restaurant-booking')),
            'processing' => rb_t('processing', __('Processing...', 'restaurant-booking')),
            'selectTime' => rb_t('select_time', __('Select time', 'restaurant-booking')),
            'people' => rb_t('people', __('people', 'restaurant-booking')),
            'connectionError' => __('Connection error. Please try again.', 'restaurant-booking'),
            'securityError' => __('Security check failed', 'restaurant-booking'),
            'fillRequired' => __('Please fill in all required fields', 'restaurant-booking'),
            'suggestedTimes' => rb_t('suggested_times', __('Suggested Times', 'restaurant-booking')),
            'back' => rb_t('back', __('Back', 'restaurant-booking')),
            'continue' => rb_t('continue', __('Continue', 'restaurant-booking')),
            'checkingAvailability' => rb_t('check_availability', __('Check Availability', 'restaurant-booking')),
            'confirmBooking' => rb_t('confirm_booking', __('Confirm Booking', 'restaurant-booking')),
            'invalidEmail' => rb_t('invalid_email', __('Please enter a valid email address', 'restaurant-booking')),
            'invalidPhone' => rb_t('invalid_phone', __('Please enter a valid phone number', 'restaurant-booking')),
            'languageSwitching' => rb_t('language_switching', __('Switching language…', 'restaurant-booking')),
            'languageSwitched' => rb_t('language_switched', __('Language switched', 'restaurant-booking')),
            'languageSwitchFailed' => rb_t('language_switch_failed', __('Could not change language. Please try again.', 'restaurant-booking')),
        ));
    }

    /**
     * Print small inline CSS helpers.
     */
    public function print_inline_styles() {
        if (!$this->should_enqueue_assets()) {
            return;
        }
        ?>
        <style>
            body.rb-modal-open {
                overflow: hidden;
                padding-right: 15px;
            }

            .rb-new-suggestion-btn.selected {
                background: #5c4033 !important;
                color: #ffffff !important;
                border-color: #5c4033 !important;
                transform: translateY(-1px);
            }

            .rb-new-loading {
                position: relative;
                pointer-events: none;
            }

            .rb-new-loading::after {
                content: "";
                position: absolute;
                top: 50%;
                left: 50%;
                width: 16px;
                height: 16px;
                margin: -8px 0 0 -8px;
                border: 2px solid transparent;
                border-top: 2px solid currentColor;
                border-radius: 50%;
                animation: rb-spin 1s linear infinite;
            }

            @keyframes rb-spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }

            .rb-new-form-group input.error,
            .rb-new-form-group select.error,
            .rb-new-form-group textarea.error {
                border-color: #d6a39b !important;
                box-shadow: 0 0 0 3px rgba(214, 163, 155, 0.18) !important;
            }

            .rb-new-result.success {
                animation: rb-success-pulse 0.6s ease-in-out;
            }

            @keyframes rb-success-pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.02); }
                100% { transform: scale(1); }
            }
        </style>
        <?php
    }

    /**
     * Determine whether assets should be loaded on the current request.
     *
     * @return bool
     */
    private function should_enqueue_assets() {
        if (null !== $this->should_enqueue) {
            return $this->should_enqueue;
        }

        if (is_admin()) {
            $this->should_enqueue = false;
            return false;
        }

        $should_enqueue = apply_filters('rb_should_enqueue_new_frontend_assets', false);

        if ($should_enqueue) {
            $this->should_enqueue = true;
            return true;
        }

        $shortcodes = array('restaurant_booking', 'restaurant_booking_portal');

        if (is_singular()) {
            global $post;
            if ($post && isset($post->post_content)) {
                foreach ($shortcodes as $shortcode) {
                    if (has_shortcode($post->post_content, $shortcode)) {
                        $this->should_enqueue = true;
                        return true;
                    }
                }
            }
        }

        if (is_front_page() || is_home()) {
            $queried_id = get_queried_object_id();
            if ($queried_id) {
                $content = get_post_field('post_content', $queried_id);
                if ($content) {
                    foreach ($shortcodes as $shortcode) {
                        if (has_shortcode($content, $shortcode)) {
                            $this->should_enqueue = true;
                            return true;
                        }
                    }
                }
            }
        }

        $this->should_enqueue = false;
        return false;
    }
}

RB_Assets_Manager::get_instance();

