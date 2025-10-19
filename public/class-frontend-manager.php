<?php
/**
 * Location manager portal surface.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Frontend_Manager extends RB_Frontend_Base {

    private static $instance = null;

    private $portal_account_manager;
    private $portal_session;
    private $portal_account = null;
    private $manager_login_error = '';
    private $active_location_id = 0;

    private function ensure_manager_assets() {
        if (is_admin()) {
            return;
        }

        if (!function_exists('rb_frontend_enqueue_scripts')) {
            return;
        }

        add_filter('rb_enqueue_legacy_frontend_assets', '__return_true', 10, 0);
        rb_frontend_enqueue_scripts();
        remove_filter('rb_enqueue_legacy_frontend_assets', '__return_true', 10);
    }

    private function t($key, $fallback, $context = '') {
        if (function_exists('rb_t')) {
            return rb_t($key, $fallback, $context);
        }

        return $fallback;
    }

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
        $this->init_portal_account_support();
        $this->init_ajax_handlers();
        add_action('template_redirect', array($this, 'maybe_handle_manager_login'));
    }

    private function init_portal_account_support() {
        if (class_exists('RB_Portal_Account_Manager') && class_exists('RB_Portal_Session_Manager')) {
            $this->portal_account_manager = RB_Portal_Account_Manager::get_instance();
            $this->portal_session = new RB_Portal_Session_Manager();
        }
    }

    private function init_ajax_handlers() {
        add_action('wp_ajax_rb_manager_update_booking', array($this, 'handle_manager_update_booking'));
        add_action('wp_ajax_nopriv_rb_manager_update_booking', array($this, 'handle_manager_update_booking'));
        add_action('wp_ajax_rb_manager_save_booking', array($this, 'handle_manager_save_booking'));
        add_action('wp_ajax_nopriv_rb_manager_save_booking', array($this, 'handle_manager_save_booking'));
        add_action('wp_ajax_rb_manager_create_booking', array($this, 'handle_manager_create_booking'));
        add_action('wp_ajax_nopriv_rb_manager_create_booking', array($this, 'handle_manager_create_booking'));
        add_action('wp_ajax_rb_manager_add_table', array($this, 'handle_manager_add_table'));
        add_action('wp_ajax_nopriv_rb_manager_add_table', array($this, 'handle_manager_add_table'));
        add_action('wp_ajax_rb_manager_toggle_table', array($this, 'handle_manager_toggle_table'));
        add_action('wp_ajax_nopriv_rb_manager_toggle_table', array($this, 'handle_manager_toggle_table'));
        add_action('wp_ajax_rb_manager_delete_table', array($this, 'handle_manager_delete_table'));
        add_action('wp_ajax_nopriv_rb_manager_delete_table', array($this, 'handle_manager_delete_table'));
        add_action('wp_ajax_rb_manager_set_customer_vip', array($this, 'handle_manager_set_customer_vip'));
        add_action('wp_ajax_nopriv_rb_manager_set_customer_vip', array($this, 'handle_manager_set_customer_vip'));
        add_action('wp_ajax_rb_manager_set_customer_blacklist', array($this, 'handle_manager_set_customer_blacklist'));
        add_action('wp_ajax_nopriv_rb_manager_set_customer_blacklist', array($this, 'handle_manager_set_customer_blacklist'));
        add_action('wp_ajax_rb_manager_customer_history', array($this, 'handle_manager_customer_history'));
        add_action('wp_ajax_nopriv_rb_manager_customer_history', array($this, 'handle_manager_customer_history'));
        add_action('wp_ajax_rb_manager_update_customer_note', array($this, 'handle_manager_update_customer_note'));
        add_action('wp_ajax_nopriv_rb_manager_update_customer_note', array($this, 'handle_manager_update_customer_note'));
        add_action('wp_ajax_rb_manager_update_settings', array($this, 'handle_manager_update_settings'));
        add_action('wp_ajax_nopriv_rb_manager_update_settings', array($this, 'handle_manager_update_settings'));
    }

    public function maybe_handle_manager_login() {
        if (is_admin()) {
            return;
        }

        if (!isset($_POST['rb_manager_login_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['rb_manager_login_nonce'], 'rb_manager_login')) {
            $this->manager_login_error = __('Security check failed. Please try again.', 'restaurant-booking');
            return;
        }

        $identifier_raw = isset($_POST['rb_username']) ? wp_unslash($_POST['rb_username']) : '';
        $identifier = trim(sanitize_text_field($identifier_raw));
        $password = isset($_POST['rb_password']) ? wp_unslash($_POST['rb_password']) : '';
        $language_code = isset($_POST['rb_language']) ? sanitize_text_field(wp_unslash($_POST['rb_language'])) : '';

        if ($identifier === '' || $password === '') {
            $this->manager_login_error = __('Please provide both username/email and password.', 'restaurant-booking');
            return;
        }

        if (!$this->portal_account_manager || !$this->portal_session) {
            $this->manager_login_error = __('Portal account authentication is not available. Please contact an administrator.', 'restaurant-booking');
            return;
        }

        $account = $this->portal_account_manager->authenticate($identifier, $password);

        if ($account instanceof WP_Error) {
            $this->manager_login_error = $account->get_error_message();
            return;
        }

        if (!$account) {
            $this->manager_login_error = __('Invalid credentials. Please try again.', 'restaurant-booking');
            return;
        }

        $allowed_locations = !empty($account->locations) ? array_map('intval', (array) $account->locations) : array();

        if (empty($allowed_locations)) {
            $this->manager_login_error = __('This account is not assigned to any locations. Please contact an administrator.', 'restaurant-booking');
            return;
        }

        $locations = $this->get_locations_data();
        $resolved_location = $this->resolve_location_from_allowed(
            0,
            $locations,
            $allowed_locations,
            isset($account->last_location_id) ? (int) $account->last_location_id : 0
        );

        if (!$resolved_location) {
            $this->manager_login_error = __('The selected location is not available for this account.', 'restaurant-booking');
            return;
        }

        if ($this->portal_session) {
            $this->portal_session->start_session($account->id);
        }

        if ($this->portal_account_manager) {
            $this->portal_account_manager->set_active_location($account->id, $resolved_location);
        }
        $account->last_location_id = $resolved_location;
        $account->locations = $allowed_locations;
        $this->portal_account = $account;

        if (!empty($language_code) && function_exists('rb_get_available_languages') && class_exists('RB_I18n')) {
            $available_languages = rb_get_available_languages();
            if (isset($available_languages[$language_code])) {
                RB_I18n::get_instance()->set_language($language_code);
            }
        }

        wp_safe_redirect(esc_url_raw(add_query_arg(array())));
        exit;
    }

    public function render_location_manager($atts) {
        $atts = shortcode_atts(array(
            'title' => $this->t('location_manager_title', __('Location Manager', 'restaurant-booking'))
        ), $atts, 'restaurant_booking_manager');

        $locations = $this->get_locations_data();
        if (empty($locations)) {
            return '<div class="rb-manager rb-alert">' . esc_html($this->t('locations_not_configured', __('Locations are not configured yet.', 'restaurant-booking'))) . '</div>';
        }

        if (isset($_POST['rb_manager_logout'])) {
            check_admin_referer('rb_manager_logout', 'rb_manager_logout_nonce');
            if ($this->portal_session) {
                $this->portal_session->destroy_session();
                $this->portal_account = null;
            }

            wp_safe_redirect(esc_url_raw(add_query_arg(array())));
            exit;
        }

        $manager_permissions = $this->get_current_manager_permissions();

        if (!$manager_permissions) {
            return $this->render_manager_login($atts, $locations);
        }

        $this->ensure_manager_assets();

        $allowed_location_ids = $manager_permissions['allowed_locations'];
        $filtered_locations = $locations;

        if (!empty($allowed_location_ids)) {
            $filtered_locations = array_values(array_filter($filtered_locations, function ($location) use ($allowed_location_ids) {
                return in_array((int) $location['id'], $allowed_location_ids, true);
            }));
        }

        if (empty($filtered_locations)) {
            return '<div class="rb-manager rb-alert">' . esc_html($this->t('no_assigned_locations', __('No locations have been assigned to your account. Please contact an administrator.', 'restaurant-booking'))) . '</div>';
        }

        $selected_location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
        $manager_name = '';

        $account = $manager_permissions['account'];

        if (empty($allowed_location_ids)) {
            $allowed_location_ids = array_map('intval', wp_list_pluck($filtered_locations, 'id'));
        }

        $fallback_location = isset($account->last_location_id) ? (int) $account->last_location_id : 0;
        $selected_location_id = $this->resolve_location_from_allowed($selected_location_id, $filtered_locations, $allowed_location_ids, $fallback_location);

        if (!$selected_location_id) {
            return '<div class="rb-manager rb-alert">' . esc_html__('Selected location is no longer available. Please contact an administrator.', 'restaurant-booking') . '</div>';
        }

        if ($this->portal_account_manager) {
            $this->portal_account_manager->set_active_location($account->id, $selected_location_id);
        }

        $account->last_location_id = $selected_location_id;
        $this->portal_account = $account;
        $this->active_location_id = $selected_location_id;
        $manager_name = !empty($account->display_name) ? $account->display_name : $account->username;

        $locations = $filtered_locations;

        global $rb_booking;
        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $filters = $this->get_dashboard_filters();

        $booking_query = array(
            'location_id' => $selected_location_id,
            'status' => $filters['filter_status'],
            'source' => $filters['filter_source'],
            'date_from' => $filters['filter_date_from'],
            'date_to' => $filters['filter_date_to'],
            'orderby' => $filters['sort_by'],
            'order' => $filters['sort_order'],
            'search' => $filters['search_term'],
        );

        $bookings = $rb_booking->get_bookings($booking_query);
        $stats = method_exists($rb_booking, 'get_location_stats') ? $rb_booking->get_location_stats($selected_location_id) : array();
        $source_stats = method_exists($rb_booking, 'get_source_stats') ? $rb_booking->get_source_stats($selected_location_id) : array();

        $location_lookup = wp_list_pluck($locations, null, 'id');
        $active_location = isset($location_lookup[$selected_location_id]) ? $location_lookup[$selected_location_id] : $locations[0];

        global $rb_location;
        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $location_settings = $rb_location ? $rb_location->get_settings($selected_location_id) : array();
        $section = isset($_GET['rb_section']) ? sanitize_key($_GET['rb_section']) : 'dashboard';
        $allowed_sections = array('dashboard', 'create', 'tables', 'customers', 'settings');
        if (!in_array($section, $allowed_sections, true)) {
            $section = 'dashboard';
        }

        $ajax_nonce = wp_create_nonce('rb_frontend_nonce');

        $nav_items = array(
            'dashboard' => array('icon' => '📊', 'label' => $this->t('dashboard', __('Dashboard', 'restaurant-booking'))),
            'create' => array('icon' => '📝', 'label' => $this->t('create_booking', __('Create Booking', 'restaurant-booking'))),
            'tables' => array('icon' => '🍽️', 'label' => $this->t('manage_tables', __('Manage Tables', 'restaurant-booking'))),
            'customers' => array('icon' => '👥', 'label' => $this->t('customers', __('Customers', 'restaurant-booking'))),
            'settings' => array('icon' => '⚙️', 'label' => $this->t('location_settings', __('Location Settings', 'restaurant-booking'))),
        );

        $available_languages = isset($location_settings['languages']) ? (array) $location_settings['languages'] : array();
        $language_definitions = function_exists('rb_get_available_languages') ? rb_get_available_languages() : array();
        $available_language_labels = array();

        foreach ($available_languages as $code) {
            $code = sanitize_text_field($code);
            if (isset($language_definitions[$code])) {
                $info = $language_definitions[$code];
                $label = isset($info['flag']) ? $info['flag'] . ' ' : '';
                $label .= isset($info['name']) ? $info['name'] : $code;
                $available_language_labels[] = $label;
            } elseif (!empty($code)) {
                $available_language_labels[] = strtoupper($code);
            }
        }

        ob_start();
        $manager_classes = array('rb-manager', 'rb-manager--gmail');

        ?>
        <div class="<?php echo esc_attr(implode(' ', $manager_classes)); ?>" data-location="<?php echo esc_attr($selected_location_id); ?>">
            <div class="rb-manager-header">
                <div class="rb-manager-header-left">
                    <div class="rb-gmail-header-title">
                        <h2><?php echo esc_html($atts['title']); ?></h2>
                        <?php if (!empty($active_location)) : ?>
                            <div class="rb-manager-location-display">
                                <span class="rb-manager-location-label"><?php echo esc_html($this->t('location', __('Location', 'restaurant-booking'))); ?>:</span>
                                <strong class="rb-manager-location-name"><?php echo esc_html($active_location['name']); ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                    <nav class="rb-gmail-header-nav" aria-label="<?php echo esc_attr($this->t('manager_sections', __('Manager sections', 'restaurant-booking'))); ?>">
                        <ul>
                            <?php foreach ($nav_items as $key => $item) :
                                $url = add_query_arg(array(
                                    'location_id' => $selected_location_id,
                                    'rb_section' => $key,
                                ), remove_query_arg(array('rb_section')));
                                ?>
                                <li class="<?php echo $section === $key ? 'is-active' : ''; ?>">
                                    <a href="<?php echo esc_url($url); ?>">
                                        <span class="rb-gmail-nav-icon" aria-hidden="true"><?php echo esc_html($item['icon']); ?></span>
                                        <span class="rb-gmail-nav-label"><?php echo esc_html($item['label']); ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </nav>
                </div>
                <div class="rb-manager-header-right">
                    <?php if (class_exists('RB_Language_Switcher')) : ?>
                        <div class="rb-manager-language-switcher">
                            <?php
                            $switcher = new RB_Language_Switcher();
                            echo $switcher->render_dropdown(false);
                            ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($manager_name)) : ?>
                        <div class="rb-manager-user">
                            <?php printf(esc_html($this->t('logged_in_as', __('Logged in as %s', 'restaurant-booking'))), esc_html($manager_name)); ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" class="rb-manager-logout">
                        <?php wp_nonce_field('rb_manager_logout', 'rb_manager_logout_nonce'); ?>
                        <button type="submit" name="rb_manager_logout" class="rb-btn-secondary"><?php echo esc_html($this->t('logout', __('Log out', 'restaurant-booking'))); ?></button>
                    </form>
                </div>
            </div>

            <?php
            $section_markup = '';
            switch ($section) {
                case 'create':
                    $section_markup = $this->render_section_create_booking($location_settings, $selected_location_id, $active_location, $ajax_nonce);
                    break;
                case 'tables':
                    $section_markup = $this->render_section_tables($selected_location_id, $ajax_nonce);
                    break;
                case 'customers':
                    $section_markup = $this->render_section_customers($selected_location_id, $ajax_nonce);
                    break;
                case 'settings':
                    $section_markup = $this->render_section_settings($location_settings, $selected_location_id, $ajax_nonce);
                    break;
                case 'dashboard':
                default:
                    $section = 'dashboard';
                    $section_markup = $this->render_section_dashboard($bookings, $ajax_nonce, $filters, $stats, $source_stats, $selected_location_id);
                    break;
            }

            $body_classes = array('rb-manager-body');
            if ($section === 'dashboard') {
                $body_classes[] = 'rb-manager-body--dashboard';
            }
            ?>

            <div class="<?php echo esc_attr(implode(' ', $body_classes)); ?>">
                <?php if ($section === 'dashboard') : ?>
                    <?php echo $section_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php else : ?>
                    <div class="rb-manager-gmail-page" data-section="<?php echo esc_attr($section); ?>">
                        <?php echo $section_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_gmail_location_summary_card($location, $language_labels, $location_settings) {
        if (empty($location) || !is_array($location)) {
            return '';
        }

        $shift_notes = isset($location_settings['shift_notes']) ? trim($location_settings['shift_notes']) : '';

        ob_start();
        ?>
        <section class="rb-gmail-location-card">
            <div class="rb-gmail-location-card__header">
                <h3><?php echo esc_html($location['name']); ?></h3>
                <?php if (!empty($language_labels)) : ?>
                    <span class="rb-gmail-location-card__badge">🌐 <?php echo esc_html(implode(', ', $language_labels)); ?></span>
                <?php endif; ?>
            </div>
            <ul class="rb-gmail-location-card__meta">
                <?php if (!empty($location['address'])) : ?>
                    <li>📍 <?php echo esc_html($location['address']); ?></li>
                <?php endif; ?>
                <?php if (!empty($location['hotline'])) : ?>
                    <li>📞 <?php echo esc_html($location['hotline']); ?></li>
                <?php endif; ?>
                <?php if (!empty($location['email'])) : ?>
                    <li>✉️ <?php echo esc_html($location['email']); ?></li>
                <?php endif; ?>
            </ul>
            <?php if ($shift_notes !== '') : ?>
                <div class="rb-gmail-location-card__notes">
                    <strong><?php echo esc_html($this->t('shift_notes', __('Shift notes', 'restaurant-booking'))); ?>:</strong>
                    <p><?php echo esc_html($shift_notes); ?></p>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    private function render_section_dashboard($bookings, $ajax_nonce, $filters, $stats, $source_stats, $location_id) {
        $status_options = array(
            '' => $this->t('all_statuses', __('All statuses', 'restaurant-booking')),
            'pending' => $this->t('pending', __('Pending', 'restaurant-booking')),
            'confirmed' => $this->t('confirmed', __('Confirmed', 'restaurant-booking')),
            'completed' => $this->t('completed', __('Completed', 'restaurant-booking')),
            'cancelled' => $this->t('cancelled', __('Cancelled', 'restaurant-booking')),
        );

        $source_options = array(
            '' => $this->t('all_sources', __('All sources', 'restaurant-booking')),
            'website' => '🌐 ' . $this->t('website', __('Website', 'restaurant-booking')),
            'phone' => '📞 ' . $this->t('phone', __('Phone', 'restaurant-booking')),
            'facebook' => '📘 Facebook',
            'zalo' => '💬 Zalo',
            'instagram' => '📷 Instagram',
            'walk-in' => '🚶 ' . $this->t('walk_in', __('Walk-in', 'restaurant-booking')),
            'email' => '✉️ ' . $this->t('email', __('Email', 'restaurant-booking')),
            'other' => '❓ ' . $this->t('other', __('Other', 'restaurant-booking')),
        );

        $sort_options = array(
            'created_at' => $this->t('created_time', __('Created time', 'restaurant-booking')),
            'booking_date' => $this->t('booking_date', __('Booking date', 'restaurant-booking')),
            'booking_time' => $this->t('booking_time', __('Booking time', 'restaurant-booking')),
            'guest_count' => $this->t('guest_count', __('Guest count', 'restaurant-booking')),
            'status' => $this->t('status', __('Status', 'restaurant-booking')),
        );

        $order_options = array(
            'DESC' => $this->t('newest_first', __('Newest first', 'restaurant-booking')),
            'ASC' => $this->t('oldest_first', __('Oldest first', 'restaurant-booking')),
        );

        $reset_url = add_query_arg(
            array(
                'location_id' => $location_id,
                'rb_section' => 'dashboard',
            ),
            remove_query_arg(array('filter_status', 'filter_source', 'filter_date_from', 'filter_date_to', 'sort_by', 'sort_order', 'search'))
        );

        $detail_labels = array(
            'empty' => $this->t('select_booking_to_view', __('Select a booking to view details', 'restaurant-booking')),
            'contact' => $this->t('contact_information', __('Contact information', 'restaurant-booking')),
            'booking' => $this->t('booking_details', __('Booking details', 'restaurant-booking')),
            'notes' => $this->t('notes', __('Notes', 'restaurant-booking')),
            'actions' => $this->t('actions', __('Actions', 'restaurant-booking')),
            'phone' => $this->t('phone', __('Phone', 'restaurant-booking')),
            'email' => $this->t('email', __('Email', 'restaurant-booking')),
            'date' => $this->t('date', __('Date', 'restaurant-booking')),
            'time' => $this->t('time', __('Time', 'restaurant-booking')),
            'guests' => $this->t('guests', __('Guests', 'restaurant-booking')),
            'source' => $this->t('source', __('Source', 'restaurant-booking')),
            'table' => $this->t('table', __('Table', 'restaurant-booking')),
            'created' => $this->t('created', __('Created', 'restaurant-booking')),
            'special' => $this->t('special_requests', __('Special requests', 'restaurant-booking')),
            'internal' => $this->t('internal_notes', __('Internal notes', 'restaurant-booking')),
        );

        $status_filters = array(
            '' => array(
                'label' => $this->t('all', __('All', 'restaurant-booking')),
                'icon' => '📬',
                'count' => $stats['total'] ?? count($bookings),
            ),
            'pending' => array(
                'label' => $this->t('pending', __('Pending', 'restaurant-booking')),
                'icon' => '⏳',
                'count' => $stats['pending'] ?? 0,
            ),
            'confirmed' => array(
                'label' => $this->t('confirmed', __('Confirmed', 'restaurant-booking')),
                'icon' => '✓',
                'count' => $stats['confirmed'] ?? 0,
            ),
            'completed' => array(
                'label' => $this->t('completed', __('Completed', 'restaurant-booking')),
                'icon' => '✓✓',
                'count' => $stats['completed'] ?? 0,
            ),
            'cancelled' => array(
                'label' => $this->t('cancelled', __('Cancelled', 'restaurant-booking')),
                'icon' => '✕',
                'count' => $stats['cancelled'] ?? 0,
            ),
        );

        $source_counts = array();
        if (!empty($source_stats)) {
            foreach ($source_stats as $source_stat) {
                $source_key = isset($source_stat->booking_source) ? $source_stat->booking_source : '';
                if ($source_key !== '') {
                    $source_counts[$source_key] = (int) $source_stat->total;
                }
            }
        }

        $source_filters = array(
            '' => array('label' => $this->t('all', __('All', 'restaurant-booking')), 'icon' => '🌐'),
            'website' => array('label' => $this->t('website', __('Website', 'restaurant-booking')), 'icon' => '🌐'),
            'phone' => array('label' => $this->t('phone', __('Phone', 'restaurant-booking')), 'icon' => '📞'),
            'facebook' => array('label' => 'Facebook', 'icon' => '📘'),
            'zalo' => array('label' => 'Zalo', 'icon' => '💬'),
            'instagram' => array('label' => 'Instagram', 'icon' => '📷'),
            'walk-in' => array('label' => $this->t('walk_in', __('Walk-in', 'restaurant-booking')), 'icon' => '🚶'),
            'email' => array('label' => $this->t('email', __('Email', 'restaurant-booking')), 'icon' => '✉️'),
            'other' => array('label' => $this->t('other', __('Other', 'restaurant-booking')), 'icon' => '❓'),
        );

        $search_placeholder = $this->t('search_bookings_placeholder', __('Search bookings…', 'restaurant-booking'));
        $list_count_label = sprintf(__('Bookings (%d)', 'restaurant-booking'), count($bookings));

        ob_start();
        ?>
        <div class="rb-manager-dashboard rb-manager-dashboard-gmail">
            <div class="rb-manager-gmail-layout" data-location-id="<?php echo esc_attr($location_id); ?>">
                <aside class="rb-gmail-sidebar is-collapsed" data-rb-sidebar>
                    <div class="rb-gmail-sidebar-inner">
                        <?php if (!empty($stats)) : ?>
                            <div class="rb-gmail-sidebar-section rb-gmail-sidebar-stats">
                                <h3 class="rb-gmail-sidebar-title"><?php echo esc_html($this->t('today', __('Today', 'restaurant-booking'))); ?></h3>
                                <dl class="rb-gmail-stat-list">
                                    <div class="rb-gmail-stat-item rb-gmail-stat-item--total">
                                        <dt><?php echo esc_html($this->t('bookings_today', __('Bookings today', 'restaurant-booking'))); ?></dt>
                                        <dd><?php echo esc_html(number_format_i18n($stats['today'] ?? 0)); ?></dd>
                                    </div>
                                    <div class="rb-gmail-stat-item">
                                        <dt><?php echo esc_html($this->t('pending_today', __('Pending today', 'restaurant-booking'))); ?></dt>
                                        <dd><?php echo esc_html(number_format_i18n($stats['today_pending'] ?? 0)); ?></dd>
                                    </div>
                                    <div class="rb-gmail-stat-item">
                                        <dt><?php echo esc_html($this->t('confirmed_today', __('Confirmed today', 'restaurant-booking'))); ?></dt>
                                        <dd><?php echo esc_html(number_format_i18n($stats['today_confirmed'] ?? 0)); ?></dd>
                                    </div>
                                    <div class="rb-gmail-stat-item">
                                        <dt><?php echo esc_html($this->t('completed_today', __('Completed today', 'restaurant-booking'))); ?></dt>
                                        <dd><?php echo esc_html(number_format_i18n($stats['today_completed'] ?? 0)); ?></dd>
                                    </div>
                                    <div class="rb-gmail-stat-item">
                                        <dt><?php echo esc_html($this->t('cancelled_today', __('Cancelled today', 'restaurant-booking'))); ?></dt>
                                        <dd><?php echo esc_html(number_format_i18n($stats['today_cancelled'] ?? 0)); ?></dd>
                                    </div>
                                </dl>
                            </div>
                        <?php endif; ?>

                        <div class="rb-gmail-sidebar-section rb-gmail-sidebar-nav" role="navigation" aria-label="<?php echo esc_attr($this->t('booking_status_filters', __('Booking status filters', 'restaurant-booking'))); ?>">
                            <h3 class="rb-gmail-sidebar-title"><?php echo esc_html($this->t('status', __('Status', 'restaurant-booking'))); ?></h3>
                            <ul class="rb-gmail-status-list">
                                <?php foreach ($status_filters as $status_key => $info) :
                                    $status_url = add_query_arg(
                                        array(
                                            'filter_status' => $status_key,
                                            'location_id' => $location_id,
                                            'rb_section' => 'dashboard',
                                        ),
                                        remove_query_arg(array('filter_status'))
                                    );
                                    $is_active = $filters['filter_status'] === $status_key;
                                    ?>
                                    <li>
                                        <a href="<?php echo esc_url($status_url); ?>" class="rb-gmail-status-link <?php echo $is_active ? 'is-active' : ''; ?>">
                                            <span class="rb-gmail-status-icon" aria-hidden="true"><?php echo esc_html($info['icon']); ?></span>
                                            <span class="rb-gmail-status-label"><?php echo esc_html($info['label']); ?></span>
                                            <span class="rb-gmail-status-count"><?php echo esc_html(number_format_i18n($info['count'])); ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <div class="rb-gmail-sidebar-section rb-gmail-sidebar-nav" role="navigation" aria-label="<?php echo esc_attr($this->t('booking_source_filters', __('Booking source filters', 'restaurant-booking'))); ?>">
                            <h3 class="rb-gmail-sidebar-title"><?php echo esc_html($this->t('source', __('Source', 'restaurant-booking'))); ?></h3>
                            <ul class="rb-gmail-source-list">
                                <?php foreach ($source_filters as $source_key => $info) :
                                    $source_url = add_query_arg(
                                        array(
                                            'filter_source' => $source_key,
                                            'location_id' => $location_id,
                                            'rb_section' => 'dashboard',
                                        ),
                                        remove_query_arg(array('filter_source'))
                                    );
                                    $is_active = $filters['filter_source'] === $source_key;
                                    $count = $source_key !== '' ? ($source_counts[$source_key] ?? 0) : ($stats['total'] ?? count($bookings));
                                    ?>
                                    <li>
                                        <a href="<?php echo esc_url($source_url); ?>" class="rb-gmail-source-link <?php echo $is_active ? 'is-active' : ''; ?>">
                                            <span class="rb-gmail-source-icon" aria-hidden="true"><?php echo esc_html($info['icon']); ?></span>
                                            <span class="rb-gmail-source-label"><?php echo esc_html($info['label']); ?></span>
                                            <span class="rb-gmail-source-count"><?php echo esc_html(number_format_i18n($count)); ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </aside>

                <div class="rb-gmail-main">
                    <div class="rb-gmail-toolbar" role="search">
                        <button type="button" class="rb-gmail-toggle" data-rb-toggle-sidebar aria-label="<?php echo esc_attr($this->t('toggle_sidebar', __('Toggle sidebar', 'restaurant-booking'))); ?>">☰</button>
                        <form class="rb-manager-filter-search" method="get">
                            <input type="hidden" name="location_id" value="<?php echo esc_attr($location_id); ?>">
                            <input type="hidden" name="rb_section" value="dashboard">
                            <?php if (!empty($filters['filter_status'])) : ?>
                                <input type="hidden" name="filter_status" value="<?php echo esc_attr($filters['filter_status']); ?>">
                            <?php endif; ?>
                            <?php if (!empty($filters['filter_source'])) : ?>
                                <input type="hidden" name="filter_source" value="<?php echo esc_attr($filters['filter_source']); ?>">
                            <?php endif; ?>
                            <?php if (!empty($filters['filter_date_from'])) : ?>
                                <input type="hidden" name="filter_date_from" value="<?php echo esc_attr($filters['filter_date_from']); ?>">
                            <?php endif; ?>
                            <?php if (!empty($filters['filter_date_to'])) : ?>
                                <input type="hidden" name="filter_date_to" value="<?php echo esc_attr($filters['filter_date_to']); ?>">
                            <?php endif; ?>
                            <?php if (!empty($filters['sort_by'])) : ?>
                                <input type="hidden" name="sort_by" value="<?php echo esc_attr($filters['sort_by']); ?>">
                            <?php endif; ?>
                            <?php if (!empty($filters['sort_order'])) : ?>
                                <input type="hidden" name="sort_order" value="<?php echo esc_attr($filters['sort_order']); ?>">
                            <?php endif; ?>
                            <div class="rb-gmail-search rb-list-search">
                                <span aria-hidden="true">🔍</span>
                                <input type="text" name="search" value="<?php echo esc_attr($filters['search_term']); ?>" placeholder="<?php echo esc_attr($search_placeholder); ?>" autocomplete="off">
                            </div>
                        </form>

                        <form class="rb-gmail-filter-form" method="get">
                            <input type="hidden" name="location_id" value="<?php echo esc_attr($location_id); ?>">
                            <input type="hidden" name="rb_section" value="dashboard">
                            <?php if (!empty($filters['search_term'])) : ?>
                                <input type="hidden" name="search" value="<?php echo esc_attr($filters['search_term']); ?>">
                            <?php endif; ?>
                            <label class="rb-gmail-filter-form__status">
                                <span><?php echo esc_html($this->t('status', __('Status', 'restaurant-booking'))); ?></span>
                                <select name="filter_status">
                                    <?php foreach ($status_filters as $value => $info) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['filter_status'], $value); ?>><?php echo esc_html($info['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span><?php echo esc_html($this->t('source', __('Source', 'restaurant-booking'))); ?></span>
                                <select name="filter_source">
                                    <?php foreach ($source_options as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['filter_source'], $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span><?php echo esc_html($this->t('from_date', __('From date', 'restaurant-booking'))); ?></span>
                                <input type="date" name="filter_date_from" value="<?php echo esc_attr($filters['filter_date_from']); ?>">
                            </label>
                            <label>
                                <span><?php echo esc_html($this->t('to_date', __('To date', 'restaurant-booking'))); ?></span>
                                <input type="date" name="filter_date_to" value="<?php echo esc_attr($filters['filter_date_to']); ?>">
                            </label>
                            <label>
                                <span><?php echo esc_html($this->t('sort_by', __('Sort by', 'restaurant-booking'))); ?></span>
                                <select name="sort_by">
                                    <?php foreach ($sort_options as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['sort_by'], $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span><?php echo esc_html($this->t('order', __('Order', 'restaurant-booking'))); ?></span>
                                <select name="sort_order">
                                    <?php foreach ($order_options as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected(strtoupper($filters['sort_order']), $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div class="rb-gmail-filter-actions">
                                <button type="submit" class="rb-btn-primary"><?php echo esc_html($this->t('apply_filters', __('Apply filters', 'restaurant-booking'))); ?></button>
                                <a class="rb-btn-secondary" href="<?php echo esc_url($reset_url); ?>"><?php echo esc_html($this->t('reset', __('Reset', 'restaurant-booking'))); ?></a>
                            </div>
                        </form>
                    </div>

                    <div class="rb-gmail-list" role="list" aria-label="<?php echo esc_attr($list_count_label); ?>">
                        <?php if (!empty($bookings)) : ?>
                            <?php foreach ($bookings as $index => $booking) :
                                $status_label = $this->format_booking_status($booking->status);
                                $created = !empty($booking->created_at) ? date_i18n(get_option('date_format', 'd/m/Y') . ' H:i', strtotime($booking->created_at)) : '';
                                $note = !empty($booking->special_requests) ? $booking->special_requests : '';
                                $admin_note = !empty($booking->admin_notes) ? $booking->admin_notes : '';
                                $date_display = !empty($booking->booking_date) ? date_i18n(get_option('date_format', 'd/m/Y'), strtotime($booking->booking_date)) : '';
                                $padded_id = str_pad($booking->id, 5, '0', STR_PAD_LEFT);
                                $source_label = $this->format_booking_source($booking->booking_source);
                                $initials = strtoupper(substr(trim($booking->customer_name), 0, 1));
                                $card_classes = array('rb-booking-card', 'rb-booking-item', 'status-' . sanitize_html_class($booking->status));
                                if ($booking->status === 'pending') {
                                    $card_classes[] = 'is-unread';
                                }
                                ?>
                                <article
                                    class="<?php echo esc_attr(implode(' ', array_map('sanitize_html_class', $card_classes))); ?>"
                                    data-booking-id="<?php echo esc_attr($booking->id); ?>"
                                    data-padded-id="<?php echo esc_attr($padded_id); ?>"
                                    data-customer-name="<?php echo esc_attr($booking->customer_name); ?>"
                                    data-customer-phone="<?php echo esc_attr($booking->customer_phone); ?>"
                                    data-customer-email="<?php echo esc_attr($booking->customer_email); ?>"
                                    data-booking-date="<?php echo esc_attr($booking->booking_date); ?>"
                                    data-booking-time="<?php echo esc_attr($booking->booking_time); ?>"
                                    data-date-display="<?php echo esc_attr($date_display); ?>"
                                    data-guest-count="<?php echo esc_attr($booking->guest_count); ?>"
                                    data-booking-source="<?php echo esc_attr($booking->booking_source); ?>"
                                    data-source-label="<?php echo esc_attr($source_label); ?>"
                                    data-special-requests="<?php echo esc_attr($note); ?>"
                                    data-admin-notes="<?php echo esc_attr($admin_note); ?>"
                                    data-status="<?php echo esc_attr($booking->status); ?>"
                                    data-status-label="<?php echo esc_attr($status_label); ?>"
                                    data-table-number="<?php echo esc_attr(isset($booking->table_number) ? $booking->table_number : ''); ?>"
                                    data-created-display="<?php echo esc_attr($created); ?>"
                                    data-rb-index="<?php echo esc_attr($index); ?>"
                                    role="listitem"
                                    tabindex="0"
                                >
                                    <div class="rb-booking-avatar rb-booking-item-avatar" aria-hidden="true"><?php echo esc_html($initials !== '' ? $initials : '•'); ?></div>
                                    <div class="rb-booking-card-body rb-booking-item-content">
                                        <header class="rb-booking-card-header">
                                            <div class="rb-booking-card-title-group">
                                                <h4 class="rb-booking-card-name rb-booking-item-name"><?php echo esc_html($booking->customer_name); ?></h4>
                                                <?php if ($padded_id !== '') : ?>
                                                    <span class="rb-booking-card-id">#<?php echo esc_html($padded_id); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="rb-booking-card-time">
                                                <?php if ($date_display !== '') : ?>
                                                    <span class="rb-booking-item-time"><?php echo esc_html($date_display); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($booking->booking_time)) : ?>
                                                    <span class="rb-booking-item-slot"><?php echo esc_html($booking->booking_time); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </header>
                                        <div class="rb-booking-card-meta rb-booking-item-meta">
                                            <?php if (!empty($booking->customer_phone)) : ?>
                                                <span class="rb-booking-meta-item" data-meta="phone">📞 <?php echo esc_html($booking->customer_phone); ?></span>
                                            <?php endif; ?>
                                            <span class="rb-booking-meta-item" data-meta="guests">👥 <?php echo esc_html($booking->guest_count); ?></span>
                                            <span class="rb-booking-meta-item rb-booking-badge rb-booking-status-badge <?php echo esc_attr(sanitize_html_class($booking->status)); ?>" data-meta="status"><?php echo esc_html($status_label); ?></span>
                                        </div>
                                        <div class="rb-booking-card-footer">
                                            <?php if (!empty($source_label)) : ?>
                                                <span class="rb-booking-meta-item" data-meta="source"><?php echo esc_html($source_label); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($created)) : ?>
                                                <span class="rb-booking-meta-item" data-meta="created">⏱ <?php echo esc_html($created); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($note)) : ?>
                                            <p class="rb-booking-card-note" data-meta="special">📝 <?php echo esc_html($note); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <div class="rb-gmail-empty">
                                <span class="rb-gmail-empty-icon" aria-hidden="true">📭</span>
                                <p><?php echo esc_html($this->t('no_reservations_found_for_this_location', __('No reservations found for this location.', 'restaurant-booking'))); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <aside class="rb-gmail-detail" data-rb-detail>
                    <button type="button" class="rb-gmail-detail-close" data-rb-close-detail aria-label="<?php echo esc_attr($this->t('close_detail', __('Close detail panel', 'restaurant-booking'))); ?>">&times;</button>
                    <div class="rb-gmail-detail-scroll">
                        <div
                            id="rb-manager-detail"
                            data-nonce="<?php echo esc_attr($ajax_nonce); ?>"
                            data-empty-message="<?php echo esc_attr($detail_labels['empty']); ?>"
                            data-contact-label="<?php echo esc_attr($detail_labels['contact']); ?>"
                            data-booking-label="<?php echo esc_attr($detail_labels['booking']); ?>"
                            data-notes-label="<?php echo esc_attr($detail_labels['notes']); ?>"
                            data-actions-label="<?php echo esc_attr($detail_labels['actions']); ?>"
                            data-phone-label="<?php echo esc_attr($detail_labels['phone']); ?>"
                            data-email-label="<?php echo esc_attr($detail_labels['email']); ?>"
                            data-date-label="<?php echo esc_attr($detail_labels['date']); ?>"
                            data-time-label="<?php echo esc_attr($detail_labels['time']); ?>"
                            data-guests-label="<?php echo esc_attr($detail_labels['guests']); ?>"
                            data-source-label="<?php echo esc_attr($detail_labels['source']); ?>"
                            data-table-label="<?php echo esc_attr($detail_labels['table']); ?>"
                            data-created-label="<?php echo esc_attr($detail_labels['created']); ?>"
                            data-special-label="<?php echo esc_attr($detail_labels['special']); ?>"
                            data-internal-label="<?php echo esc_attr($detail_labels['internal']); ?>"
                        >
                            <div class="rb-detail-empty">
                                👈 <?php echo esc_html($detail_labels['empty']); ?>
                            </div>
                        </div>
                    </div>
                </aside>
                <button
                    type="button"
                    class="rb-gmail-overlay"
                    data-rb-close-panels
                    aria-label="<?php echo esc_attr($this->t('close', __('Close', 'restaurant-booking'))); ?>"
                ></button>
            </div>

            <div id="rb-manager-feedback" class="rb-portal-result" hidden data-nonce="<?php echo esc_attr($ajax_nonce); ?>"></div>
            <div id="rb-manager-edit-modal" class="rb-manager-modal" hidden>
                <div class="rb-manager-modal-dialog">
                    <button type="button" class="rb-manager-modal-close" aria-label="<?php echo esc_attr($this->t('close', __('Close', 'restaurant-booking'))); ?>">&times;</button>
                    <form id="rb-manager-edit-booking-form">
                        <input type="hidden" name="action" value="rb_manager_save_booking">
                        <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                        <input type="hidden" name="location_id" value="<?php echo esc_attr($location_id); ?>">
                        <div class="rb-manager-form-grid">
                            <label>
                                <?php echo esc_html($this->t('customer_name', __('Customer name', 'restaurant-booking'))); ?>
                                <input type="text" name="customer_name" required>
                            </label>
                            <label>
                                <?php echo esc_html($this->t('phone_number', __('Phone number', 'restaurant-booking'))); ?>
                                <input type="tel" name="customer_phone" required pattern="[0-9]{8,15}">
                            </label>
                            <label>
                                <?php echo esc_html($this->t('email', __('Email', 'restaurant-booking'))); ?>
                                <input type="email" name="customer_email" required>
                            </label>
                            <label>
                                <?php echo esc_html($this->t('guests', __('Guests', 'restaurant-booking'))); ?>
                                <input type="number" name="guest_count" min="1" max="50" required>
                            </label>
                            <label>
                                <?php echo esc_html($this->t('date', __('Date', 'restaurant-booking'))); ?>
                                <input type="date" name="booking_date" required>
                            </label>
                            <label>
                                <?php echo esc_html($this->t('time', __('Time', 'restaurant-booking'))); ?>
                                <input type="time" name="booking_time" required>
                            </label>
                            <label>
                                <?php echo esc_html($this->t('source', __('Source', 'restaurant-booking'))); ?>
                                <select name="booking_source">
                                    <?php foreach ($source_options as $value => $label) : ?>
                                        <?php if ($value === '') { continue; } ?>
                                        <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <label class="rb-manager-wide">
                            <?php echo esc_html($this->t('special_requests', __('Special requests', 'restaurant-booking'))); ?>
                            <textarea name="special_requests" rows="3"></textarea>
                        </label>
                        <label class="rb-manager-wide">
                            <?php echo esc_html($this->t('internal_notes', __('Internal notes', 'restaurant-booking'))); ?>
                            <textarea name="admin_notes" rows="3"></textarea>
                        </label>
                        <div class="rb-manager-actions-row">
                            <button type="submit" class="rb-btn-primary"><?php echo esc_html($this->t('save_changes', __('Save changes', 'restaurant-booking'))); ?></button>
                            <button type="button" class="rb-btn-secondary rb-manager-modal-cancel"><?php echo esc_html($this->t('cancel', __('Cancel', 'restaurant-booking'))); ?></button>
                        </div>
                    </form>
                    <div id="rb-manager-edit-feedback" class="rb-portal-result" hidden></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_dashboard_filters() {
        $status = isset($_GET['filter_status']) ? sanitize_text_field(wp_unslash($_GET['filter_status'])) : '';
        $source = isset($_GET['filter_source']) ? sanitize_text_field(wp_unslash($_GET['filter_source'])) : '';
        $date_from = isset($_GET['filter_date_from']) ? sanitize_text_field(wp_unslash($_GET['filter_date_from'])) : '';
        $date_to = isset($_GET['filter_date_to']) ? sanitize_text_field(wp_unslash($_GET['filter_date_to'])) : '';
        $sort_by = isset($_GET['sort_by']) ? sanitize_key($_GET['sort_by']) : 'created_at';
        $sort_order = isset($_GET['sort_order']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['sort_order']))) : 'DESC';
        $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';

        $allowed_status = array('pending', 'confirmed', 'completed', 'cancelled');
        if (!in_array($status, $allowed_status, true)) {
            $status = '';
        }

        $allowed_sources = array('website', 'phone', 'facebook', 'zalo', 'instagram', 'walk-in', 'email', 'other');
        if (!in_array($source, $allowed_sources, true)) {
            $source = '';
        }

        $allowed_sort = array('created_at', 'booking_date', 'booking_time', 'guest_count', 'status');
        if (!in_array($sort_by, $allowed_sort, true)) {
            $sort_by = 'created_at';
        }

        if (!in_array($sort_order, array('ASC', 'DESC'), true)) {
            $sort_order = 'DESC';
        }

        return array(
            'filter_status' => $status,
            'filter_source' => $source,
            'filter_date_from' => $date_from,
            'filter_date_to' => $date_to,
            'sort_by' => $sort_by,
            'sort_order' => $sort_order,
            'search_term' => $search,
        );
    }

    private function format_booking_status($status) {
        switch ($status) {
            case 'pending':
                return __('Pending', 'restaurant-booking');
            case 'confirmed':
                return __('Confirmed', 'restaurant-booking');
            case 'completed':
                return __('Completed', 'restaurant-booking');
            case 'cancelled':
                return __('Cancelled', 'restaurant-booking');
            default:
                return ucfirst($status);
        }
    }

    private function format_booking_source($source) {
        switch ($source) {
            case 'website':
                return __('Website', 'restaurant-booking');
            case 'phone':
                return __('Phone', 'restaurant-booking');
            case 'facebook':
                return 'Facebook';
            case 'zalo':
                return 'Zalo';
            case 'instagram':
                return 'Instagram';
            case 'walk-in':
                return __('Walk-in', 'restaurant-booking');
            case 'email':
                return __('Email', 'restaurant-booking');
            case 'other':
                return __('Other', 'restaurant-booking');
            default:
                return $source;
        }
    }

    private function get_customer_filters() {
        $search = isset($_GET['customer_search']) ? sanitize_text_field(wp_unslash($_GET['customer_search'])) : '';
        $vip = isset($_GET['filter_vip']) ? sanitize_text_field(wp_unslash($_GET['filter_vip'])) : 'all';
        $blacklist = isset($_GET['filter_blacklist']) ? sanitize_text_field(wp_unslash($_GET['filter_blacklist'])) : '';
        $orderby = isset($_GET['customer_orderby']) ? sanitize_key($_GET['customer_orderby']) : 'total_bookings';
        $order = isset($_GET['customer_order']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['customer_order']))) : 'DESC';

        $allowed_vip = array('all', 'yes');
        if (!in_array($vip, $allowed_vip, true)) {
            $vip = 'all';
        }

        $allowed_blacklist = array('', 'yes', 'no');
        if (!in_array($blacklist, $allowed_blacklist, true)) {
            $blacklist = '';
        }

        $allowed_orderby = array('total_bookings', 'completed_bookings', 'cancelled_bookings', 'last_visit', 'name');
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'total_bookings';
        }

        if (!in_array($order, array('ASC', 'DESC'), true)) {
            $order = 'DESC';
        }

        return array(
            'search' => $search,
            'vip' => $vip,
            'blacklist' => $blacklist,
            'orderby' => $orderby,
            'order' => $order,
        );
    }

    private function format_booking_for_response($booking) {
        if (!$booking) {
            return null;
        }

        $date_display = '';
        if (!empty($booking->booking_date)) {
            $timestamp = strtotime($booking->booking_date);
            if ($timestamp) {
                $date_display = date_i18n(get_option('date_format', 'd/m/Y'), $timestamp);
            }
        }

        $created_display = '';
        if (!empty($booking->created_at)) {
            $created_timestamp = strtotime($booking->created_at);
            if ($created_timestamp) {
                $created_display = date_i18n(get_option('date_format', 'd/m/Y') . ' H:i', $created_timestamp);
            }
        }

        return array(
            'id' => (int) $booking->id,
            'padded_id' => str_pad($booking->id, 5, '0', STR_PAD_LEFT),
            'customer_name' => $booking->customer_name,
            'customer_phone' => $booking->customer_phone,
            'customer_email' => $booking->customer_email,
            'booking_date' => $booking->booking_date,
            'booking_time' => $booking->booking_time,
            'guest_count' => (int) $booking->guest_count,
            'booking_source' => $booking->booking_source,
            'special_requests' => $booking->special_requests,
            'admin_notes' => $booking->admin_notes,
            'status' => $booking->status,
            'table_number' => isset($booking->table_number) ? $booking->table_number : '',
            'created_at' => isset($booking->created_at) ? $booking->created_at : '',
            'status_label' => $this->format_booking_status($booking->status),
            'source_label' => $this->format_booking_source($booking->booking_source),
            'date_display' => $date_display,
            'created_display' => $created_display,
        );
    }

    private function render_section_create_booking($location_settings, $location_id, $location_details, $ajax_nonce) {
        $opening_time = isset($location_settings['opening_time']) ? substr($location_settings['opening_time'], 0, 5) : '09:00';
        $closing_time = isset($location_settings['closing_time']) ? substr($location_settings['closing_time'], 0, 5) : '22:00';
        $time_interval = isset($location_settings['time_slot_interval']) ? intval($location_settings['time_slot_interval']) : 30;
        $time_slots = $this->generate_time_slots($opening_time, $closing_time, $time_interval);

        $min_hours = isset($location_settings['min_advance_booking']) ? intval($location_settings['min_advance_booking']) : 2;
        if ($min_hours < 0) {
            $min_hours = 0;
        }
        $max_days = isset($location_settings['max_advance_booking']) ? intval($location_settings['max_advance_booking']) : 30;
        if ($max_days <= 0) {
            $max_days = 30;
        }

        $now = current_time('timestamp');
        $min_timestamp = $now + ($min_hours * HOUR_IN_SECONDS);
        if ($min_timestamp < $now) {
            $min_timestamp = $now;
        }
        $min_date = date('Y-m-d', $min_timestamp);
        $max_date = date('Y-m-d', $now + ($max_days * DAY_IN_SECONDS));

        ob_start();
        ?>
        <div class="rb-manager-create">
            <h3><?php echo esc_html($this->t('create_a_new_reservation', __('Create a new reservation', 'restaurant-booking'))); ?></h3>
            <form id="rb-manager-create-booking" method="post">
                <input type="hidden" name="location_id" value="<?php echo esc_attr($location_id); ?>">
                <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                <div class="rb-form-grid">
                    <label>
                        <?php echo esc_html($this->t('customer_name', __('Customer name', 'restaurant-booking'))); ?> *
                        <input type="text" name="customer_name" required>
                    </label>
                    <label>
                        <?php echo esc_html($this->t('phone_number', __('Phone number', 'restaurant-booking'))); ?> *
                        <input type="tel" name="customer_phone" required pattern="[0-9]{8,15}">
                    </label>
                    <label>
                        <?php echo esc_html($this->t('email', __('Email', 'restaurant-booking'))); ?> *
                        <input type="email" name="customer_email" required>
                    </label>
                    <label>
                        <?php echo esc_html($this->t('guests', __('Guests', 'restaurant-booking'))); ?> *
                        <select name="guest_count" required>
                            <?php for ($i = 1; $i <= 20; $i++) : ?>
                                <option value="<?php echo esc_attr($i); ?>"><?php echo esc_html($i); ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <label>
                        <?php echo esc_html($this->t('date', __('Date', 'restaurant-booking'))); ?> *
                        <input type="date" name="booking_date" min="<?php echo esc_attr($min_date); ?>" max="<?php echo esc_attr($max_date); ?>" required>
                    </label>
                    <label>
                        <?php echo esc_html($this->t('time', __('Time', 'restaurant-booking'))); ?> *
                        <select name="booking_time" required>
                            <option value=""><?php echo esc_html($this->t('select_a_time', __('Select a time', 'restaurant-booking'))); ?></option>
                            <?php foreach ($time_slots as $slot) : ?>
                                <option value="<?php echo esc_attr($slot); ?>"><?php echo esc_html($slot); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <?php echo esc_html($this->t('source', __('Source', 'restaurant-booking'))); ?>
                        <select name="booking_source">
                            <option value="phone">📞 <?php echo esc_html($this->t('phone', __('Phone', 'restaurant-booking'))); ?></option>
                            <option value="facebook">📘 Facebook</option>
                            <option value="zalo">💬 Zalo</option>
                            <option value="instagram">📷 Instagram</option>
                            <option value="walk-in">🚶 <?php echo esc_html($this->t('walk_in', __('Walk-in', 'restaurant-booking'))); ?></option>
                            <option value="email">✉️ Email</option>
                            <option value="other">❓ <?php echo esc_html($this->t('other', __('Other', 'restaurant-booking'))); ?></option>
                        </select>
                    </label>
                </div>

                <label class="rb-manager-wide">
                    <?php echo esc_html($this->t('special_requests', __('Special requests', 'restaurant-booking'))); ?>
                    <textarea name="special_requests" rows="3"></textarea>
                </label>

                <label class="rb-manager-wide">
                    <?php echo esc_html($this->t('internal_notes', __('Internal notes', 'restaurant-booking'))); ?>
                    <textarea name="admin_notes" rows="3"></textarea>
                </label>

                <label class="rb-manager-checkbox">
                    <input type="checkbox" name="auto_confirm" value="1">
                    <?php echo esc_html($this->t('confirm_immediately_if_a_table_is_available', __('Confirm immediately if a table is available', 'restaurant-booking'))); ?>
                </label>

                <div class="rb-manager-actions-row">
                    <button type="submit" class="rb-btn-primary"><?php echo esc_html($this->t('create_booking', __('Create booking', 'restaurant-booking'))); ?></button>
                </div>
            </form>
            <div id="rb-manager-create-feedback" class="rb-portal-result" hidden></div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_section_tables($location_id, $ajax_nonce) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rb_tables';
        $tables = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE location_id = %d ORDER BY table_number",
            $location_id
        ));

        ob_start();
        ?>
        <div class="rb-manager-tables">
            <h3><?php echo esc_html($this->t('table_management', __('Table management', 'restaurant-booking'))); ?></h3>
            <form id="rb-manager-add-table" class="rb-manager-add-table" method="post">
                <input type="hidden" name="location_id" value="<?php echo esc_attr($location_id); ?>">
                <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                <label>
                    <?php echo esc_html($this->t('table_number', __('Table number', 'restaurant-booking'))); ?>
                    <input type="number" name="table_number" min="1" required>
                </label>
                <label>
                    <?php echo esc_html($this->t('capacity', __('Capacity', 'restaurant-booking'))); ?>
                    <input type="number" name="capacity" min="1" required>
                </label>
                <button type="submit" class="rb-btn-primary"><?php echo esc_html($this->t('add_table', __('Add table', 'restaurant-booking'))); ?></button>
            </form>
            <div id="rb-manager-table-feedback" class="rb-portal-result" hidden></div>

            <div class="rb-manager-bookings-table-wrapper">
                <table class="rb-manager-bookings-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html($this->t('table', __('Table', 'restaurant-booking'))); ?></th>
                            <th><?php echo esc_html($this->t('capacity', __('Capacity', 'restaurant-booking'))); ?></th>
                            <th><?php echo esc_html($this->t('status', __('Status', 'restaurant-booking'))); ?></th>
                            <th><?php echo esc_html($this->t('actions', __('Actions', 'restaurant-booking'))); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($tables)) : ?>
                            <?php
                            $table_label = $this->t('table', __('Table', 'restaurant-booking'));
                            $capacity_label = $this->t('capacity', __('Capacity', 'restaurant-booking'));
                            $status_label = $this->t('status', __('Status', 'restaurant-booking'));
                            $actions_label = $this->t('actions', __('Actions', 'restaurant-booking'));
                            foreach ($tables as $table) :
                                $is_available = (int) $table->is_available === 1;
                                $next_status = $is_available ? 0 : 1;
                                ?>
                                <tr>
                                    <td data-label="<?php echo esc_attr($table_label); ?>"><?php echo esc_html($table->table_number); ?></td>
                                    <td data-label="<?php echo esc_attr($capacity_label); ?>"><?php echo esc_html($table->capacity); ?></td>
                                    <td data-label="<?php echo esc_attr($status_label); ?>">
                                        <?php if ($is_available) : ?>
                                            <span class="rb-status rb-status-available"><?php echo esc_html($this->t('available', __('Available', 'restaurant-booking'))); ?></span>
                                        <?php else : ?>
                                            <span class="rb-status rb-status-unavailable"><?php echo esc_html($this->t('unavailable', __('Unavailable', 'restaurant-booking'))); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?php echo esc_attr($actions_label); ?>">
                                        <button class="rb-btn-secondary rb-manager-toggle-table" data-table-id="<?php echo esc_attr($table->id); ?>" data-next-status="<?php echo esc_attr($next_status); ?>">
                                            <?php echo $is_available ? esc_html__('Deactivate', 'restaurant-booking') : esc_html__('Activate', 'restaurant-booking'); ?>
                                        </button>
                                        <button class="rb-btn-danger rb-manager-delete-table" data-table-id="<?php echo esc_attr($table->id); ?>">
                                            <?php echo esc_html($this->t('delete', __('Delete', 'restaurant-booking'))); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="4" class="rb-manager-empty"><?php echo esc_html($this->t('no_tables_configured_for_this_location', __('No tables configured for this location.', 'restaurant-booking'))); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_section_customers($location_id, $ajax_nonce) {
        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        $filters = $this->get_customer_filters();

        $query_args = array(
            'location_id' => $location_id,
            'limit' => 100,
            'orderby' => $filters['orderby'],
            'order' => $filters['order'],
            'search' => $filters['search'],
        );

        if ($filters['vip'] === 'yes') {
            $query_args['vip_only'] = true;
        }

        if ($filters['blacklist'] !== '') {
            $query_args['blacklisted'] = $filters['blacklist'] === 'yes' ? 1 : 0;
        }

        $customers = $rb_customer->get_customers($query_args);
        $stats = $rb_customer->get_stats($location_id);
        $total_stat = isset($stats['total']) ? (int) $stats['total'] : 0;
        $vip_stat = isset($stats['vip']) ? (int) $stats['vip'] : 0;
        $blacklisted_stat = isset($stats['blacklisted']) ? (int) $stats['blacklisted'] : 0;
        $new_stat = isset($stats['new_this_month']) ? (int) $stats['new_this_month'] : 0;
        $active_stat = max(0, $total_stat - $blacklisted_stat);
        $vip_suggestions = $rb_customer->get_vip_suggestions($location_id);
        $problematic = $rb_customer->get_problematic_customers($location_id);

        $reset_url = add_query_arg(array(
            'location_id' => $location_id,
            'rb_section' => 'customers',
        ), remove_query_arg(array('customer_search', 'filter_vip', 'filter_blacklist', 'customer_orderby', 'customer_order')));

        $sidebar_filters = array(
            array(
                'label' => $this->t('all_customers', __('All customers', 'restaurant-booking')),
                'icon' => '📬',
                'count' => $total_stat ?: count($customers),
                'url' => $reset_url,
                'active' => $filters['vip'] === 'all' && $filters['blacklist'] === '' && empty($filters['search']),
            ),
            array(
                'label' => $this->t('vip_only', __('VIP only', 'restaurant-booking')),
                'icon' => '⭐',
                'count' => $vip_stat,
                'url' => add_query_arg('filter_vip', 'yes', $reset_url),
                'active' => $filters['vip'] === 'yes',
            ),
            array(
                'label' => $this->t('blacklisted', __('Blacklisted', 'restaurant-booking')),
                'icon' => '🚫',
                'count' => $blacklisted_stat,
                'url' => add_query_arg('filter_blacklist', 'yes', $reset_url),
                'active' => $filters['blacklist'] === 'yes',
            ),
            array(
                'label' => $this->t('active_customers', __('Active customers', 'restaurant-booking')),
                'icon' => '✅',
                'count' => $active_stat,
                'url' => add_query_arg('filter_blacklist', 'no', $reset_url),
                'active' => $filters['blacklist'] === 'no',
            ),
        );

        $total_customers = count($customers);

        ob_start();
        ?>
        <div class="rb-manager-customers">
            <h3><?php echo esc_html($this->t('customer_management', __('Customer management', 'restaurant-booking'))); ?></h3>

            <div class="rb-customer-inbox">
                <div class="rb-inbox-toolbar">
                    <form class="rb-inbox-toolbar__form" method="get">
                        <input type="hidden" name="location_id" value="<?php echo esc_attr($location_id); ?>">
                        <input type="hidden" name="rb_section" value="customers">

                        <div class="rb-inbox-toolbar__field rb-inbox-toolbar__search">
                            <label for="rb-customer-search"><?php echo esc_html($this->t('search', __('Search', 'restaurant-booking'))); ?></label>
                            <input id="rb-customer-search" type="search" name="customer_search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php echo esc_attr($this->t('name_phone_or_email', __('Name, phone or email', 'restaurant-booking'))); ?>">
                        </div>

                        <div class="rb-inbox-toolbar__field">
                            <label for="rb-customer-vip"><?php echo esc_html($this->t('vip_filter', __('VIP filter', 'restaurant-booking'))); ?></label>
                            <select id="rb-customer-vip" name="filter_vip">
                                <option value="all" <?php selected($filters['vip'], 'all'); ?>><?php echo esc_html($this->t('all_customers', __('All customers', 'restaurant-booking'))); ?></option>
                                <option value="yes" <?php selected($filters['vip'], 'yes'); ?>><?php echo esc_html($this->t('vip_only', __('VIP only', 'restaurant-booking'))); ?></option>
                            </select>
                        </div>

                        <div class="rb-inbox-toolbar__field">
                            <label for="rb-customer-blacklist"><?php echo esc_html($this->t('blacklist', __('Blacklist', 'restaurant-booking'))); ?></label>
                            <select id="rb-customer-blacklist" name="filter_blacklist">
                                <option value="" <?php selected($filters['blacklist'], ''); ?>><?php echo esc_html($this->t('all', __('All', 'restaurant-booking'))); ?></option>
                                <option value="yes" <?php selected($filters['blacklist'], 'yes'); ?>><?php echo esc_html($this->t('blacklisted', __('Blacklisted', 'restaurant-booking'))); ?></option>
                                <option value="no" <?php selected($filters['blacklist'], 'no'); ?>><?php echo esc_html($this->t('not_blacklisted', __('Not blacklisted', 'restaurant-booking'))); ?></option>
                            </select>
                        </div>

                        <div class="rb-inbox-toolbar__field">
                            <label for="rb-customer-orderby"><?php echo esc_html($this->t('sort_by', __('Sort by', 'restaurant-booking'))); ?></label>
                            <select id="rb-customer-orderby" name="customer_orderby">
                                <option value="total_bookings" <?php selected($filters['orderby'], 'total_bookings'); ?>><?php echo esc_html($this->t('total_bookings', __('Total bookings', 'restaurant-booking'))); ?></option>
                                <option value="completed_bookings" <?php selected($filters['orderby'], 'completed_bookings'); ?>><?php echo esc_html($this->t('completed_bookings', __('Completed bookings', 'restaurant-booking'))); ?></option>
                                <option value="cancelled_bookings" <?php selected($filters['orderby'], 'cancelled_bookings'); ?>><?php echo esc_html($this->t('cancelled_bookings', __('Cancelled bookings', 'restaurant-booking'))); ?></option>
                                <option value="last_visit" <?php selected($filters['orderby'], 'last_visit'); ?>><?php echo esc_html($this->t('last_visit', __('Last visit', 'restaurant-booking'))); ?></option>
                                <option value="name" <?php selected($filters['orderby'], 'name'); ?>><?php echo esc_html($this->t('name', __('Name', 'restaurant-booking'))); ?></option>
                            </select>
                        </div>

                        <div class="rb-inbox-toolbar__field">
                            <label for="rb-customer-order"><?php echo esc_html($this->t('order', __('Order', 'restaurant-booking'))); ?></label>
                            <select id="rb-customer-order" name="customer_order">
                                <option value="DESC" <?php selected($filters['order'], 'DESC'); ?>><?php echo esc_html($this->t('descending', __('Descending', 'restaurant-booking'))); ?></option>
                                <option value="ASC" <?php selected($filters['order'], 'ASC'); ?>><?php echo esc_html($this->t('ascending', __('Ascending', 'restaurant-booking'))); ?></option>
                            </select>
                        </div>

                        <div class="rb-inbox-toolbar__actions">
                            <button type="submit" class="rb-btn-primary"><?php echo esc_html($this->t('apply_filters', __('Apply filters', 'restaurant-booking'))); ?></button>
                            <a class="rb-btn-secondary" href="<?php echo esc_url($reset_url); ?>"><?php echo esc_html($this->t('reset', __('Reset', 'restaurant-booking'))); ?></a>
                        </div>
                    </form>
                </div>

                <div class="rb-inbox-layout">
                    <aside class="rb-inbox-sidebar rb-gmail-sidebar">
                        <div class="rb-gmail-sidebar-inner">
                            <div class="rb-gmail-sidebar-section rb-gmail-sidebar-stats">
                                <h3 class="rb-gmail-sidebar-title"><?php echo esc_html($this->t('customer_overview', __('Customer overview', 'restaurant-booking'))); ?></h3>
                                <dl class="rb-gmail-stat-list">
                                    <div class="rb-gmail-stat-item rb-gmail-stat-item--total">
                                        <dt><?php echo esc_html($this->t('total_customers', __('Total customers', 'restaurant-booking'))); ?></dt>
                                        <dd><?php echo esc_html(number_format_i18n($total_stat)); ?></dd>
                                    </div>
                                    <div class="rb-gmail-stat-item">
                                        <dt><?php echo esc_html($this->t('vip_customers', __('VIP customers', 'restaurant-booking'))); ?></dt>
                                        <dd><?php echo esc_html(number_format_i18n($vip_stat)); ?></dd>
                                    </div>
                                    <div class="rb-gmail-stat-item">
                                        <dt><?php echo esc_html($this->t('blacklisted', __('Blacklisted', 'restaurant-booking'))); ?></dt>
                                        <dd><?php echo esc_html(number_format_i18n($blacklisted_stat)); ?></dd>
                                    </div>
                                    <div class="rb-gmail-stat-item">
                                        <dt><?php echo esc_html($this->t('new_this_month', __('New this month', 'restaurant-booking'))); ?></dt>
                                        <dd><?php echo esc_html(number_format_i18n($new_stat)); ?></dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="rb-gmail-sidebar-section rb-gmail-sidebar-nav">
                                <h3 class="rb-gmail-sidebar-title"><?php echo esc_html($this->t('filters', __('Filters', 'restaurant-booking'))); ?></h3>
                                <ul class="rb-gmail-status-list">
                                    <?php foreach ($sidebar_filters as $filter) : ?>
                                        <li>
                                            <a class="rb-gmail-status-link <?php echo $filter['active'] ? 'is-active' : ''; ?>" href="<?php echo esc_url($filter['url']); ?>">
                                                <span class="rb-gmail-status-icon" aria-hidden="true"><?php echo esc_html($filter['icon']); ?></span>
                                                <span class="rb-gmail-status-label"><?php echo esc_html($filter['label']); ?></span>
                                                <span class="rb-gmail-status-count"><?php echo esc_html(number_format_i18n($filter['count'])); ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <?php if (!empty($vip_suggestions)) : ?>
                                <div class="rb-gmail-sidebar-section">
                                    <div class="rb-inbox-sidebar__note rb-inbox-sidebar__note--tip">
                                        <strong><?php echo esc_html($this->t('vip_suggestions', __('VIP suggestions:', 'restaurant-booking'))); ?></strong>
                                        <p><?php printf(esc_html__('%d customers are close to VIP status.', 'restaurant-booking'), count($vip_suggestions)); ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($problematic)) : ?>
                                <div class="rb-gmail-sidebar-section">
                                    <div class="rb-inbox-sidebar__note rb-inbox-sidebar__note--warning">
                                        <strong><?php echo esc_html($this->t('attention', __('Attention:', 'restaurant-booking'))); ?></strong>
                                        <p><?php printf(esc_html__('%d customers frequently cancel or no-show.', 'restaurant-booking'), count($problematic)); ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="rb-gmail-sidebar-section rb-gmail-sidebar-summary">
                                <strong><?php printf(esc_html__('%d customers shown', 'restaurant-booking'), $total_customers); ?></strong>
                            </div>
                        </div>
                    </aside>

                    <div class="rb-inbox-main">
                        <div class="rb-inbox-main__header">
                            <strong><?php printf(esc_html__('%d matching customers', 'restaurant-booking'), $total_customers); ?></strong>
                        </div>

                        <div class="rb-inbox-list rb-customer-list" data-detail-target="rb-customer-detail">
                            <?php if (!empty($customers)) : ?>
                                <?php foreach ($customers as $customer) :
                                    $total = isset($customer->total_bookings) ? (int) $customer->total_bookings : 0;
                                    $completed = isset($customer->completed_bookings) ? (int) $customer->completed_bookings : 0;
                                    $cancelled = isset($customer->cancelled_bookings) ? (int) $customer->cancelled_bookings : 0;
                                    $no_shows = isset($customer->no_shows) ? (int) $customer->no_shows : 0;
                                    $success_rate = $total > 0 ? round(($completed / max($total, 1)) * 100) : 0;
                                    $problem_rate = $total > 0 ? round((($cancelled + $no_shows) / max($total, 1)) * 100) : 0;
                                    $last_visit = !empty($customer->last_visit) ? date_i18n('d/m/Y', strtotime($customer->last_visit)) : '';
                                    $first_visit = !empty($customer->first_visit) ? date_i18n('d/m/Y', strtotime($customer->first_visit)) : '';
                                    $notes = isset($customer->customer_notes) ? $customer->customer_notes : '';
                                    $is_vip = !empty($customer->vip_status);
                                    $is_blacklisted = !empty($customer->blacklisted);
                                    $is_loyal = $completed >= 5;
                                    $is_problem = !$is_blacklisted && $problem_rate > 50;
                                    $can_promote_vip = !$is_vip && $completed >= 3;
                                    $contact_bits = array_filter(array(
                                        !empty($customer->phone) ? $customer->phone : '',
                                        !empty($customer->email) ? $customer->email : '',
                                    ));
                                    $contact_summary = implode(' • ', $contact_bits);
                                    $note_preview = $notes ? wp_trim_words($notes, 16) : '';
                                    $has_badges = $is_vip || $is_blacklisted || $is_loyal || $is_problem;
                                    $meta_text = $last_visit
                                        ? sprintf('%s: %s', $this->t('last_visit', __('Last visit', 'restaurant-booking')), $last_visit)
                                        : sprintf('%s: %s', $this->t('total_bookings', __('Total bookings', 'restaurant-booking')), number_format_i18n($total));
                                ?>
                                    <article
                                        class="rb-inbox-item"
                                        data-customer-id="<?php echo esc_attr($customer->id); ?>"
                                        data-name="<?php echo esc_attr($customer->name); ?>"
                                        data-phone="<?php echo esc_attr($customer->phone); ?>"
                                        data-email="<?php echo esc_attr($customer->email); ?>"
                                        data-total="<?php echo esc_attr($total); ?>"
                                        data-completed="<?php echo esc_attr($completed); ?>"
                                        data-cancelled="<?php echo esc_attr($cancelled); ?>"
                                        data-no-shows="<?php echo esc_attr($no_shows); ?>"
                                        data-success-rate="<?php echo esc_attr($success_rate); ?>"
                                        data-problem-rate="<?php echo esc_attr($problem_rate); ?>"
                                        data-first-visit="<?php echo esc_attr($first_visit); ?>"
                                        data-last-visit="<?php echo esc_attr($last_visit); ?>"
                                        data-notes="<?php echo esc_attr(rawurlencode($notes)); ?>"
                                        data-is-vip="<?php echo $is_vip ? '1' : '0'; ?>"
                                        data-is-blacklisted="<?php echo $is_blacklisted ? '1' : '0'; ?>"
                                        data-is-loyal="<?php echo $is_loyal ? '1' : '0'; ?>"
                                        data-is-problem="<?php echo $is_problem ? '1' : '0'; ?>"
                                        data-can-promote-vip="<?php echo $can_promote_vip ? '1' : '0'; ?>"
                                        data-problem-count="<?php echo esc_attr($cancelled + $no_shows); ?>"
                                        data-history-phone="<?php echo esc_attr($customer->phone); ?>"
                                    >
                                        <div class="rb-inbox-item__content">
                                            <div class="rb-inbox-item__row">
                                                <span class="rb-inbox-item__title"><?php echo esc_html($customer->name); ?></span>
                                                <span class="rb-inbox-item__meta"><?php echo esc_html($meta_text); ?></span>
                                            </div>
                                            <div class="rb-inbox-item__row rb-inbox-item__badges" <?php echo $has_badges ? '' : 'hidden'; ?> data-badge-row>
                                                <span class="rb-inbox-badge rb-inbox-badge--vip" data-badge="vip" <?php echo $is_vip ? '' : 'hidden'; ?>><?php echo esc_html($this->t('vip', __('VIP', 'restaurant-booking'))); ?></span>
                                                <span class="rb-inbox-badge rb-inbox-badge--danger" data-badge="blacklist" <?php echo $is_blacklisted ? '' : 'hidden'; ?>><?php echo esc_html($this->t('blacklisted', __('Blacklisted', 'restaurant-booking'))); ?></span>
                                                <span class="rb-inbox-badge rb-inbox-badge--success" data-badge="loyal" <?php echo $is_loyal ? '' : 'hidden'; ?>><?php echo esc_html($this->t('loyal_customer', __('Loyal', 'restaurant-booking'))); ?></span>
                                                <span class="rb-inbox-badge rb-inbox-badge--warning" data-badge="problem" <?php echo $is_problem ? '' : 'hidden'; ?>><?php echo esc_html($this->t('risk', __('Risk', 'restaurant-booking'))); ?></span>
                                            </div>
                                            <div class="rb-inbox-item__row rb-inbox-item__snippet" data-contact-summary <?php echo !empty($contact_summary) ? '' : 'hidden'; ?>>
                                                <span><?php echo esc_html($contact_summary); ?></span>
                                            </div>
                                            <div class="rb-inbox-item__row rb-inbox-item__note" data-note-preview <?php echo $note_preview ? '' : 'hidden'; ?>>
                                                <span class="rb-inbox-badge rb-inbox-badge--note"><?php echo esc_html($this->t('notes', __('Notes', 'restaurant-booking'))); ?></span>
                                                <span data-note-text><?php echo esc_html($note_preview); ?></span>
                                            </div>
                                        </div>
                                        <div class="rb-inbox-item__status rb-inbox-item__metrics">
                                            <div class="rb-inbox-metric">
                                                <span class="rb-inbox-metric__label"><?php echo esc_html($this->t('total_bookings', __('Total bookings', 'restaurant-booking'))); ?></span>
                                                <strong><?php echo number_format_i18n($total); ?></strong>
                                            </div>
                                            <div class="rb-inbox-metric">
                                                <span class="rb-inbox-metric__label"><?php echo esc_html($this->t('completed_bookings', __('Completed', 'restaurant-booking'))); ?></span>
                                                <strong><?php echo number_format_i18n($completed); ?></strong>
                                                <small><?php echo esc_html($success_rate); ?>%</small>
                                            </div>
                                            <div class="rb-inbox-metric">
                                                <span class="rb-inbox-metric__label"><?php echo esc_html($this->t('problem_rate', __('Problem rate', 'restaurant-booking'))); ?></span>
                                                <strong><?php echo number_format_i18n($cancelled + $no_shows); ?></strong>
                                                <small><?php echo esc_html($problem_rate); ?>%</small>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <div class="rb-inbox-empty">
                                    <p><?php echo esc_html($this->t('no_customers_found_for_this_location', __('No customers found for this location.', 'restaurant-booking'))); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div
                            class="rb-inbox-detail rb-customer-detail"
                            id="rb-customer-detail"
                            aria-hidden="true"
                        >
                            <button
                                type="button"
                                class="rb-gmail-detail-close"
                                data-rb-close-detail
                                aria-label="<?php echo esc_attr($this->t('close_detail', __('Close detail panel', 'restaurant-booking'))); ?>"
                            >&times;</button>
                            <div class="rb-inbox-detail-empty">
                                <p><?php echo esc_html($this->t('select_customer_to_view', __('Select a customer to see details.', 'restaurant-booking'))); ?></p>
                            </div>
                            <div class="rb-inbox-detail-body" hidden>
                                <div class="rb-gmail-detail-scroll rb-customer-detail-scroll">
                                    <div class="rb-inbox-detail-header">
                                        <div>
                                            <h2 data-field="name"></h2>
                                            <div class="rb-inbox-detail-subtitle">
                                                <a href="#" data-field="phone" class="rb-inbox-detail-contact" rel="nofollow"></a>
                                                <a href="#" data-field="email" class="rb-inbox-detail-contact" rel="nofollow"></a>
                                            </div>
                                            <div class="rb-inbox-detail-tags" data-badge-row hidden>
                                                <span class="rb-inbox-badge rb-inbox-badge--vip" data-badge="vip"><?php echo esc_html($this->t('vip', __('VIP', 'restaurant-booking'))); ?></span>
                                                <span class="rb-inbox-badge rb-inbox-badge--danger" data-badge="blacklist"><?php echo esc_html($this->t('blacklisted', __('Blacklisted', 'restaurant-booking'))); ?></span>
                                                <span class="rb-inbox-badge rb-inbox-badge--success" data-badge="loyal"><?php echo esc_html($this->t('loyal_customer', __('Loyal', 'restaurant-booking'))); ?></span>
                                                <span class="rb-inbox-badge rb-inbox-badge--warning" data-badge="problem"><?php echo esc_html($this->t('risk', __('Risk', 'restaurant-booking'))); ?></span>
                                            </div>
                                        </div>
                                        <div class="rb-inbox-detail-status rb-customer-detail-stats">
                                            <div class="rb-inbox-detail-stat">
                                                <strong data-field="total">0</strong>
                                                <span><?php echo esc_html($this->t('total_bookings', __('Total bookings', 'restaurant-booking'))); ?></span>
                                            </div>
                                            <div class="rb-inbox-detail-stat">
                                                <strong data-field="completed">0</strong>
                                                <span><?php echo esc_html($this->t('completed_bookings', __('Completed', 'restaurant-booking'))); ?></span>
                                                <small data-field="success-rate"></small>
                                            </div>
                                            <div class="rb-inbox-detail-stat">
                                                <strong data-field="problem-count">0</strong>
                                                <span><?php echo esc_html($this->t('problem_rate', __('Problem rate', 'restaurant-booking'))); ?></span>
                                                <small data-field="problem-rate"></small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="rb-inbox-detail-body__content">
                                        <div class="rb-inbox-detail-meta-grid rb-customer-detail-grid">
                                            <div><strong><?php echo esc_html($this->t('last_visit', __('Last visit', 'restaurant-booking'))); ?>:</strong> <span data-field="last-visit"></span></div>
                                            <div><strong><?php echo esc_html($this->t('first_visit', __('First visit', 'restaurant-booking'))); ?>:</strong> <span data-field="first-visit"></span></div>
                                            <div><strong><?php echo esc_html($this->t('cancelled', __('Cancelled', 'restaurant-booking'))); ?>:</strong> <span data-field="cancelled"></span></div>
                                            <div><strong><?php echo esc_html($this->t('no_show', __('No-show', 'restaurant-booking'))); ?>:</strong> <span data-field="no-shows"></span></div>
                                        </div>
                                        <div class="rb-inbox-detail-section rb-customer-notes">
                                            <h4><?php echo esc_html($this->t('notes', __('Notes', 'restaurant-booking'))); ?></h4>
                                            <textarea class="rb-manager-note-field" rows="5" data-customer-id="" placeholder="<?php echo esc_attr($this->t('add_internal_note', __('Add internal note...', 'restaurant-booking'))); ?>"></textarea>
                                            <div class="rb-manager-note-actions">
                                                <button type="button" class="rb-btn-primary rb-manager-save-note" data-customer-id=""><?php echo esc_html($this->t('save_note', __('Save note', 'restaurant-booking'))); ?></button>
                                                <span class="rb-manager-note-status" aria-live="polite"></span>
                                            </div>
                                        </div>
                                        <div class="rb-inbox-detail-section rb-customer-actions">
                                            <h4><?php echo esc_html($this->t('actions', __('Actions', 'restaurant-booking'))); ?></h4>
                                            <div class="rb-manager-detail-actions">
                                                <button type="button" class="rb-btn-secondary rb-manager-view-history" data-customer-id="" data-phone=""><?php echo esc_html($this->t('history', __('History', 'restaurant-booking'))); ?></button>
                                                <button type="button" class="rb-btn-primary rb-manager-set-vip" data-customer-id="" style="display:none;">
                                                    <?php echo esc_html($this->t('set_vip', __('Set VIP', 'restaurant-booking'))); ?>
                                                </button>
                                                <button type="button" class="rb-btn-danger rb-manager-blacklist" data-customer-id="" style="display:none;">
                                                    <?php echo esc_html($this->t('blacklist', __('Blacklist', 'restaurant-booking'))); ?>
                                                </button>
                                                <button type="button" class="rb-btn-secondary rb-manager-unblacklist" data-customer-id="" style="display:none;">
                                                    <?php echo esc_html($this->t('remove_blacklist', __('Remove blacklist', 'restaurant-booking'))); ?>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="rb-gmail-overlay" data-rb-close-panels aria-hidden="true"></div>
            </div>

            <div id="rb-manager-history" class="rb-manager-history" hidden>
                <div class="rb-manager-history-inner">
                    <button type="button" class="rb-manager-history-close">&times;</button>
                    <div id="rb-manager-history-content"></div>
                </div>
            </div>

            <input type="hidden" id="rb-manager-customers-nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_section_settings($location_settings, $location_id, $ajax_nonce) {
        $active_tab = isset($_GET['rb_settings_tab']) ? sanitize_key($_GET['rb_settings_tab']) : 'general';
        $allowed_tabs = array('general', 'hours', 'booking');
        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = 'general';
        }

        $defaults = array(
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'time_slot_interval' => 30,
            'booking_buffer_time' => 0,
            'min_advance_booking' => 2,
            'max_advance_booking' => 30,
            'max_guests_per_booking' => 20,
            'working_hours_mode' => 'simple',
            'lunch_break_enabled' => 'no',
            'lunch_break_start' => '14:00',
            'lunch_break_end' => '17:00',
            'morning_shift_start' => '09:00',
            'morning_shift_end' => '14:00',
            'evening_shift_start' => '17:00',
            'evening_shift_end' => '22:00',
            'hotline' => '',
            'email' => '',
            'address' => '',
            'shift_notes' => '',
            'languages' => array(),
            'auto_confirm_enabled' => 'no',
            'require_deposit' => 'no',
            'deposit_amount' => 0,
            'deposit_for_guests' => 0,
            'admin_email' => get_option('admin_email'),
            'enable_email' => 'yes',
            'enable_sms' => 'no',
            'sms_api_key' => '',
            'reminder_hours_before' => 24,
            'special_closed_dates' => '',
            'cancellation_hours' => 2,
            'weekend_enabled' => 'yes',
            'no_show_auto_blacklist' => 3,
        );

        $settings = wp_parse_args($location_settings, $defaults);
        if (!is_array($settings['languages'])) {
            $settings['languages'] = array_filter(array_map('trim', explode(',', $settings['languages'])));
        }

        $tabs = array(
            'general' => rb_t('general_info', __('General information', 'restaurant-booking')),
            'hours' => __('Working hours', 'restaurant-booking'),
            'booking' => __('Booking rules', 'restaurant-booking'),
        );

        $language_options = function_exists('rb_get_available_languages') ? rb_get_available_languages() : array();

        ob_start();
        ?>
        <div class="rb-manager-settings">
            <h3><?php echo esc_html($this->t('location_settings', __('Location settings', 'restaurant-booking'))); ?></h3>
            <form id="rb-manager-settings-form">
                <input type="hidden" name="nonce" value="<?php echo esc_attr($ajax_nonce); ?>">
                <input type="hidden" name="location_id" value="<?php echo esc_attr($location_id); ?>">

                <div class="rb-manager-settings-tabs">
                    <?php foreach ($tabs as $slug => $label) : ?>
                        <button type="button" class="rb-manager-settings-tab <?php echo $active_tab === $slug ? 'active' : ''; ?>" data-tab="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></button>
                    <?php endforeach; ?>
                </div>

                <div class="rb-manager-settings-panels">
                    <section class="rb-manager-settings-panel" data-tab="general" <?php echo $active_tab === 'general' ? '' : 'hidden'; ?>>
                        <div class="rb-manager-settings-grid">
                            <label>
                                <?php echo esc_html(rb_t('hotline', __('Hotline', 'restaurant-booking'))); ?>
                                <input type="text" name="location_settings[hotline]" value="<?php echo esc_attr($settings['hotline']); ?>">
                            </label>
                            <label>
                                <?php echo esc_html(rb_t('contact_email', __('Contact email', 'restaurant-booking'))); ?>
                                <input type="email" name="location_settings[email]" value="<?php echo esc_attr($settings['email']); ?>">
                            </label>
                            <label class="rb-manager-wide">
                                <?php echo esc_html(rb_t('location_address', __('Address', 'restaurant-booking'))); ?>
                                <input type="text" name="location_settings[address]" value="<?php echo esc_attr($settings['address']); ?>">
                            </label>
                            <label class="rb-manager-wide">
                                <?php echo esc_html(rb_t('shift_notes', __('Shift notes', 'restaurant-booking'))); ?>
                                <textarea name="location_settings[shift_notes]" placeholder="<?php echo esc_attr(rb_t('shift_notes_placeholder', __('Important reminders for the team (optional)', 'restaurant-booking'))); ?>"><?php echo esc_textarea($settings['shift_notes']); ?></textarea>
                            </label>
                        </div>

                    </section>
                    <section class="rb-manager-settings-panel" data-tab="hours" <?php echo $active_tab === 'hours' ? '' : 'hidden'; ?>>
                        <div class="rb-manager-toggle-group">
                            <label>
                                <input type="radio" name="location_settings[working_hours_mode]" value="simple" <?php checked($settings['working_hours_mode'], 'simple'); ?>>
                                <?php echo esc_html($this->t('simple_one_opening_and_closing_time', __('Simple: one opening and closing time', 'restaurant-booking'))); ?>
                            </label>
                            <label>
                                <input type="radio" name="location_settings[working_hours_mode]" value="advanced" <?php checked($settings['working_hours_mode'], 'advanced'); ?>>
                                <?php echo esc_html($this->t('advanced_morning_evening_shifts', __('Advanced: morning/evening shifts', 'restaurant-booking'))); ?>
                            </label>
                        </div>
                        <div class="rb-manager-settings-grid">
                            <label>
                                <?php echo esc_html($this->t('opening_time', __('Opening time', 'restaurant-booking'))); ?>
                                <input type="time" name="location_settings[opening_time]" value="<?php echo esc_attr(substr($settings['opening_time'], 0, 5)); ?>">
                            </label>
                            <label>
                                <?php echo esc_html($this->t('closing_time', __('Closing time', 'restaurant-booking'))); ?>
                                <input type="time" name="location_settings[closing_time]" value="<?php echo esc_attr(substr($settings['closing_time'], 0, 5)); ?>">
                            </label>
                            <label>
                                <input type="checkbox" name="location_settings[lunch_break_enabled]" value="yes" <?php checked($settings['lunch_break_enabled'], 'yes'); ?>>
                                <?php echo esc_html($this->t('enable_lunch_break', __('Enable lunch break', 'restaurant-booking'))); ?>
                            </label>
                            <label>
                                <?php echo esc_html($this->t('lunch_break_start', __('Lunch break start', 'restaurant-booking'))); ?>
                                <input type="time" name="location_settings[lunch_break_start]" value="<?php echo esc_attr(substr($settings['lunch_break_start'], 0, 5)); ?>">
                            </label>
                            <label>
                                <?php echo esc_html($this->t('lunch_break_end', __('Lunch break end', 'restaurant-booking'))); ?>
                                <input type="time" name="location_settings[lunch_break_end]" value="<?php echo esc_attr(substr($settings['lunch_break_end'], 0, 5)); ?>">
                            </label>
                            <label>
                                <?php echo esc_html($this->t('morning_shift', __('Morning shift', 'restaurant-booking'))); ?>
                                <div class="rb-manager-shift">
                                    <input type="time" name="location_settings[morning_shift_start]" value="<?php echo esc_attr(substr($settings['morning_shift_start'], 0, 5)); ?>">
                                    <span>→</span>
                                    <input type="time" name="location_settings[morning_shift_end]" value="<?php echo esc_attr(substr($settings['morning_shift_end'], 0, 5)); ?>">
                                </div>
                            </label>
                            <label>
                                <?php echo esc_html($this->t('evening_shift', __('Evening shift', 'restaurant-booking'))); ?>
                                <div class="rb-manager-shift">
                                    <input type="time" name="location_settings[evening_shift_start]" value="<?php echo esc_attr(substr($settings['evening_shift_start'], 0, 5)); ?>">
                                    <span>→</span>
                                    <input type="time" name="location_settings[evening_shift_end]" value="<?php echo esc_attr(substr($settings['evening_shift_end'], 0, 5)); ?>">
                                </div>
                            </label>
                        </div>
                    </section>

                    <section class="rb-manager-settings-panel" data-tab="booking" <?php echo $active_tab === 'booking' ? '' : 'hidden'; ?>>
                        <div class="rb-manager-settings-grid">
                            <label>
                                <?php echo esc_html($this->t('time_slot_interval_minutes', __('Time slot interval (minutes)', 'restaurant-booking'))); ?>
                                <input type="number" name="location_settings[time_slot_interval]" min="5" max="120" step="5" value="<?php echo esc_attr((int) $settings['time_slot_interval']); ?>">
                            </label>
                            <label>
                                <?php echo esc_html($this->t('buffer_time_minutes', __('Buffer time (minutes)', 'restaurant-booking'))); ?>
                                <input type="number" name="location_settings[booking_buffer_time]" min="0" max="120" step="5" value="<?php echo esc_attr((int) $settings['booking_buffer_time']); ?>">
                            </label>
                            <label>
                                <?php echo esc_html($this->t('minimum_hours_in_advance', __('Minimum hours in advance', 'restaurant-booking'))); ?>
                                <input type="number" name="location_settings[min_advance_booking]" min="0" max="72" value="<?php echo esc_attr((int) $settings['min_advance_booking']); ?>">
                            </label>
                            <label>
                                <?php echo esc_html($this->t('maximum_days_in_advance', __('Maximum days in advance', 'restaurant-booking'))); ?>
                                <input type="number" name="location_settings[max_advance_booking]" min="1" max="180" value="<?php echo esc_attr((int) $settings['max_advance_booking']); ?>">
                            </label>
                            <label>
                                <?php echo esc_html($this->t('max_guests_per_booking', __('Max guests per booking', 'restaurant-booking'))); ?>
                                <input type="number" name="location_settings[max_guests_per_booking]" min="1" max="100" value="<?php echo esc_attr((int) $settings['max_guests_per_booking']); ?>">
                            </label>
                            <label>
                                <input type="checkbox" name="location_settings[auto_confirm_enabled]" value="yes" <?php checked($settings['auto_confirm_enabled'], 'yes'); ?>>
                                <?php echo esc_html($this->t('auto_confirm_when_a_table_is_available', __('Auto confirm when a table is available', 'restaurant-booking'))); ?>
                            </label>
                        </div>
                    </section>
                </div>

                <div class="rb-manager-actions-row">
                    <button type="submit" class="rb-btn-primary"><?php echo esc_html($this->t('save_settings', __('Save settings', 'restaurant-booking'))); ?></button>
                </div>
            </form>
            <div id="rb-manager-settings-feedback" class="rb-portal-result" hidden></div>
        </div>
        <?php
        return ob_get_clean();
    }

    protected function generate_time_slots($start = null, $end = null, $interval = null) {
        $slots = array();

        $start_time = strtotime($start);
        $end_time = strtotime($end);
        $interval = max(5, (int) $interval);

        if (!$start_time || !$end_time || $start_time >= $end_time) {
            return $slots;
        }

        while ($start_time < $end_time) {
            $slots[] = date('H:i', $start_time);
            $start_time += $interval * MINUTE_IN_SECONDS;
        }

        return $slots;
    }

    private function render_manager_login($atts, $locations) {
        $error = $this->manager_login_error;
        $languages = function_exists('rb_get_available_languages') ? rb_get_available_languages() : array();
        $current_language = function_exists('rb_get_current_language') ? rb_get_current_language() : '';

        ob_start();
        ?>
        <div class="rb-manager rb-manager-login">
            <h2><?php echo esc_html($atts['title']); ?></h2>
            <?php if (!empty($error)) : ?>
                <div class="rb-portal-result error"><?php echo wp_kses_post($error); ?></div>
            <?php endif; ?>
            <form method="post" class="rb-manager-login-form">
                <?php wp_nonce_field('rb_manager_login', 'rb_manager_login_nonce'); ?>
                <div class="rb-form-group">
                    <label for="rb-manager-username"><?php echo esc_html($this->t('username_or_email', __('Username or email', 'restaurant-booking'))); ?></label>
                    <input type="text" id="rb-manager-username" name="rb_username" required />
                </div>
                <div class="rb-form-group">
                    <label for="rb-manager-password"><?php echo esc_html($this->t('password', __('Password', 'restaurant-booking'))); ?></label>
                    <input type="password" id="rb-manager-password" name="rb_password" required />
                </div>
                <?php if (!empty($languages)) : ?>
                    <div class="rb-form-group">
                        <label for="rb-manager-language"><?php echo esc_html($this->t('interface_language', __('Interface language', 'restaurant-booking'))); ?></label>
                        <select id="rb-manager-language" name="rb_language">
                            <?php foreach ($languages as $code => $info) :
                                $label = isset($info['flag']) ? $info['flag'] . ' ' : '';
                                $label .= isset($info['name']) ? $info['name'] : $code;
                                ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($current_language, $code); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="rb-portal-actions">
                    <button type="submit" class="rb-btn-primary"><?php echo esc_html($this->t('log_in', __('Log in', 'restaurant-booking'))); ?></button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function resolve_location_from_allowed($requested_location_id, $locations, $allowed_ids, $fallback_id = 0) {
        if (empty($locations)) {
            return 0;
        }

        $available_ids = array_map('intval', wp_list_pluck($locations, 'id'));
        $available_ids = array_values(array_filter($available_ids));

        if (empty($available_ids)) {
            return 0;
        }

        $allowed_ids = array_map('intval', (array) $allowed_ids);
        $allowed_ids = array_values(array_filter($allowed_ids));

        if (!empty($allowed_ids)) {
            $allowed_ids = array_values(array_intersect($allowed_ids, $available_ids));

            if (empty($allowed_ids)) {
                return 0;
            }
        } else {
            $allowed_ids = $available_ids;
        }

        $requested_location_id = (int) $requested_location_id;
        if ($requested_location_id && in_array($requested_location_id, $allowed_ids, true)) {
            return $requested_location_id;
        }

        $fallback_id = (int) $fallback_id;
        if ($fallback_id && in_array($fallback_id, $allowed_ids, true)) {
            return $fallback_id;
        }

        return (int) $allowed_ids[0];
    }

    private function get_portal_session_account() {
        if (!$this->portal_session) {
            return null;
        }

        if ($this->portal_account === null) {
            $account = $this->portal_session->get_current_account();
            $this->portal_account = $account ? $account : false;
        }

        return $this->portal_account ? $this->portal_account : null;
    }

    private function get_current_manager_permissions() {
        $account = $this->get_portal_session_account();

        if ($account) {
            $allowed = !empty($account->locations) ? array_map('intval', (array) $account->locations) : array();

            return array(
                'account' => $account,
                'allowed_locations' => $allowed,
            );
        }

        return null;
    }

    private function manager_can_access_location($permissions, $location_id) {
        if (!$permissions) {
            return false;
        }

        $location_id = (int) $location_id;
        if ($location_id <= 0) {
            return false;
        }

        $allowed = isset($permissions['allowed_locations']) ? (array) $permissions['allowed_locations'] : array();

        if (empty($allowed)) {
            return true;
        }

        return in_array($location_id, array_map('intval', $allowed), true);
    }

    private function get_permissions_active_location($permissions) {
        if (!$permissions || empty($permissions['account'])) {
            return 0;
        }

        $account = $permissions['account'];

        return isset($account->last_location_id) ? (int) $account->last_location_id : 0;
    }

    public function handle_manager_update_booking() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        $action = isset($_POST['manager_action']) ? sanitize_text_field($_POST['manager_action']) : '';

        if (!$booking_id || empty($action)) {
            wp_send_json_error(array('message' => __('Invalid request data.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->portal_session || !$this->portal_account_manager) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        $account = $permissions['account'];
        $active_location = isset($account->last_location_id) ? (int) $account->last_location_id : 0;

        global $rb_booking;
        if (!$rb_booking) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
            $rb_booking = new RB_Booking();
        }

        $booking = $rb_booking->get_booking($booking_id);

        if (!$booking) {
            wp_send_json_error(array('message' => __('Booking not found.', 'restaurant-booking')));
            wp_die();
        }

        $booking_location = (int) $booking->location_id;
        $allowed_locations = $permissions['allowed_locations'];

        if (!empty($allowed_locations) && !in_array($booking_location, $allowed_locations, true)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this location.', 'restaurant-booking')));
            wp_die();
        }

        if ($active_location && $booking_location !== $active_location) {
            wp_send_json_error(array('message' => __('You can only manage bookings for your current location.', 'restaurant-booking')));
            wp_die();
        }

        switch ($action) {
            case 'confirm':
                $result = $rb_booking->confirm_booking($booking_id);
                if (!is_wp_error($result) && class_exists('RB_Email')) {
                    $email = new RB_Email();
                    $email->send_confirmation_email($rb_booking->get_booking($booking_id));
                }
                break;
            case 'cancel':
                $result = $rb_booking->cancel_booking($booking_id);
                break;
            case 'complete':
                $result = $rb_booking->complete_booking($booking_id);
                break;
            case 'delete':
                $result = $rb_booking->delete_booking($booking_id);
                break;
            default:
                $result = new WP_Error('rb_invalid_action', __('Unsupported action', 'restaurant-booking'));
        }

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            wp_die();
        }

        if ($action === 'delete') {
            wp_send_json_success(array('message' => __('Booking deleted successfully.', 'restaurant-booking'), 'deleted' => true));
            wp_die();
        }

        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        $rb_customer->update_customer_from_booking($booking_id, true);

        $updated_booking = $rb_booking->get_booking($booking_id);
        $formatted = $this->format_booking_for_response($updated_booking);

        wp_send_json_success(array(
            'message' => __('Booking updated successfully.', 'restaurant-booking'),
            'booking' => $formatted,
        ));

        wp_die();
    }

    public function handle_manager_create_booking() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if (!$this->manager_can_access_location($permissions, $location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this location.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== $location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before creating a booking.', 'restaurant-booking')));
            wp_die();
        }

        if (!class_exists('RB_Booking')) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
        }

        global $rb_booking;
        if (!$rb_booking) {
            $rb_booking = new RB_Booking();
        }

        $customer_name = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '';
        $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field(wp_unslash($_POST['customer_phone'])) : '';
        $customer_email = isset($_POST['customer_email']) ? sanitize_email(wp_unslash($_POST['customer_email'])) : '';
        $guest_count = isset($_POST['guest_count']) ? intval($_POST['guest_count']) : 0;
        $booking_date = isset($_POST['booking_date']) ? sanitize_text_field(wp_unslash($_POST['booking_date'])) : '';
        $booking_time = isset($_POST['booking_time']) ? sanitize_text_field(wp_unslash($_POST['booking_time'])) : '';
        $booking_source = isset($_POST['booking_source']) ? sanitize_text_field(wp_unslash($_POST['booking_source'])) : 'portal';
        $special_requests = isset($_POST['special_requests']) ? sanitize_textarea_field(wp_unslash($_POST['special_requests'])) : '';
        $admin_notes = isset($_POST['admin_notes']) ? sanitize_textarea_field(wp_unslash($_POST['admin_notes'])) : '';
        $auto_confirm = !empty($_POST['auto_confirm']);

        if (empty($customer_name) || empty($customer_phone) || empty($customer_email) || !$guest_count || empty($booking_date) || empty($booking_time)) {
            wp_send_json_error(array('message' => __('Please fill out all required fields.', 'restaurant-booking')));
            wp_die();
        }

        $is_available = $rb_booking->is_time_slot_available(
            $booking_date,
            $booking_time,
            $guest_count,
            null,
            $location_id
        );

        if (!$is_available) {
            wp_send_json_error(array('message' => __('This time slot is no longer available. Please choose a different time.', 'restaurant-booking')));
            wp_die();
        }

        $booking_data = array(
            'customer_name' => $customer_name,
            'customer_phone' => $customer_phone,
            'customer_email' => $customer_email,
            'guest_count' => $guest_count,
            'booking_date' => $booking_date,
            'booking_time' => $booking_time,
            'booking_source' => $booking_source,
            'special_requests' => $special_requests,
            'admin_notes' => $admin_notes,
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'location_id' => $location_id,
            'created_by' => 0,
        );

        $booking_id = $rb_booking->create_booking($booking_data);

        if (is_wp_error($booking_id)) {
            wp_send_json_error(array('message' => $booking_id->get_error_message()));
            wp_die();
        }

        if ($auto_confirm) {
            $result = $rb_booking->confirm_booking($booking_id);
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
                wp_die();
            }

            if (class_exists('RB_Email')) {
                $booking = $rb_booking->get_booking($booking_id);
                if ($booking) {
                    $email = new RB_Email();
                    $email->send_confirmation_email($booking);
                }
            }
        }

        wp_send_json_success(array('message' => __('Booking created successfully.', 'restaurant-booking')));
        wp_die();
    }

    public function handle_manager_save_booking() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        if (!$booking_id) {
            wp_send_json_error(array('message' => __('Invalid booking.', 'restaurant-booking')));
            wp_die();
        }

        if (!class_exists('RB_Booking')) {
            require_once RB_PLUGIN_DIR . 'includes/class-booking.php';
        }

        global $rb_booking;
        if (!$rb_booking) {
            $rb_booking = new RB_Booking();
        }

        $booking = $rb_booking->get_booking($booking_id);
        if (!$booking) {
            wp_send_json_error(array('message' => __('Booking not found.', 'restaurant-booking')));
            wp_die();
        }

        $booking_location = (int) $booking->location_id;
        $allowed_locations = $permissions['allowed_locations'];

        if (!empty($allowed_locations) && !in_array($booking_location, $allowed_locations, true)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this location.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== $booking_location) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before editing this booking.', 'restaurant-booking')));
            wp_die();
        }

        $customer_name = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '';
        $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field(wp_unslash($_POST['customer_phone'])) : '';
        $customer_email = isset($_POST['customer_email']) ? sanitize_email(wp_unslash($_POST['customer_email'])) : '';
        $guest_count = isset($_POST['guest_count']) ? intval($_POST['guest_count']) : 0;
        $booking_date = isset($_POST['booking_date']) ? sanitize_text_field(wp_unslash($_POST['booking_date'])) : '';
        $booking_time = isset($_POST['booking_time']) ? sanitize_text_field(wp_unslash($_POST['booking_time'])) : '';
        $booking_source = isset($_POST['booking_source']) ? sanitize_text_field(wp_unslash($_POST['booking_source'])) : 'portal';
        $special_requests = isset($_POST['special_requests']) ? sanitize_textarea_field(wp_unslash($_POST['special_requests'])) : '';
        $admin_notes = isset($_POST['admin_notes']) ? sanitize_textarea_field(wp_unslash($_POST['admin_notes'])) : '';

        if (empty($customer_name) || empty($customer_phone) || empty($customer_email) || !$guest_count || empty($booking_date) || empty($booking_time)) {
            wp_send_json_error(array('message' => __('Please fill out all required fields.', 'restaurant-booking')));
            wp_die();
        }

        if (!is_email($customer_email)) {
            wp_send_json_error(array('message' => __('Please provide a valid customer email.', 'restaurant-booking')));
            wp_die();
        }

        $allowed_sources = array('website', 'phone', 'facebook', 'zalo', 'instagram', 'walk-in', 'email', 'other', 'portal');
        if (!in_array($booking_source, $allowed_sources, true)) {
            $booking_source = 'portal';
        }

        $is_available = $rb_booking->is_time_slot_available(
            $booking_date,
            $booking_time,
            $guest_count,
            $booking_id,
            $booking_location
        );

        if (!$is_available) {
            wp_send_json_error(array('message' => __('This time slot is no longer available. Please choose a different time.', 'restaurant-booking')));
            wp_die();
        }

        $update_data = array(
            'customer_name' => $customer_name,
            'customer_phone' => $customer_phone,
            'customer_email' => $customer_email,
            'guest_count' => $guest_count,
            'booking_date' => $booking_date,
            'booking_time' => $booking_time,
            'booking_source' => $booking_source,
            'special_requests' => $special_requests,
            'admin_notes' => $admin_notes,
        );

        $updated = $rb_booking->update_booking($booking_id, $update_data);

        if (!$updated) {
            wp_send_json_error(array('message' => __('Could not update the booking. Please try again.', 'restaurant-booking')));
            wp_die();
        }

        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        $rb_customer->update_customer_from_booking($booking_id, true);

        $updated_booking = $rb_booking->get_booking($booking_id);
        $formatted = $this->format_booking_for_response($updated_booking);

        wp_send_json_success(array(
            'message' => __('Booking updated successfully.', 'restaurant-booking'),
            'booking' => $formatted,
        ));
        wp_die();
    }

    public function handle_manager_add_table() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if (!$this->manager_can_access_location($permissions, $location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this location.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== $location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before making changes.', 'restaurant-booking')));
            wp_die();
        }

        $table_number = isset($_POST['table_number']) ? intval($_POST['table_number']) : 0;
        $capacity = isset($_POST['capacity']) ? intval($_POST['capacity']) : 0;

        if ($table_number <= 0 || $capacity <= 0) {
            wp_send_json_error(array('message' => __('Please provide a valid table number and capacity.', 'restaurant-booking')));
            wp_die();
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rb_tables';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE table_number = %d AND location_id = %d",
            $table_number,
            $location_id
        ));

        if ($exists) {
            wp_send_json_error(array('message' => __('Table number already exists for this location.', 'restaurant-booking')));
            wp_die();
        }

        $result = $wpdb->insert(
            $table_name,
            array(
                'location_id' => $location_id,
                'table_number' => $table_number,
                'capacity' => $capacity,
                'is_available' => 1,
                'created_at' => current_time('mysql'),
            )
        );

        if ($result === false) {
            wp_send_json_error(array('message' => __('Could not add table. Please try again.', 'restaurant-booking')));
            wp_die();
        }

        wp_send_json_success(array('message' => __('Table added successfully.', 'restaurant-booking')));
        wp_die();
    }

    public function handle_manager_toggle_table() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $table_id = isset($_POST['table_id']) ? intval($_POST['table_id']) : 0;
        $is_available = isset($_POST['is_available']) ? intval($_POST['is_available']) : 0;

        global $wpdb;
        $table_name = $wpdb->prefix . 'rb_tables';
        $table = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $table_id));

        if (!$table) {
            wp_send_json_error(array('message' => __('Table not found.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->manager_can_access_location($permissions, $table->location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this location.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== (int) $table->location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before making changes.', 'restaurant-booking')));
            wp_die();
        }

        $result = $wpdb->update(
            $table_name,
            array('is_available' => $is_available ? 1 : 0),
            array('id' => $table_id)
        );

        if ($result === false) {
            wp_send_json_error(array('message' => __('Could not update the table status.', 'restaurant-booking')));
            wp_die();
        }

        wp_send_json_success(array('message' => __('Table status updated.', 'restaurant-booking')));
        wp_die();
    }

    public function handle_manager_delete_table() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $table_id = isset($_POST['table_id']) ? intval($_POST['table_id']) : 0;

        global $wpdb;
        $table_name = $wpdb->prefix . 'rb_tables';
        $table = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $table_id));

        if (!$table) {
            wp_send_json_error(array('message' => __('Table not found.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->manager_can_access_location($permissions, $table->location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this location.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== (int) $table->location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before making changes.', 'restaurant-booking')));
            wp_die();
        }

        $deleted = $wpdb->delete($table_name, array('id' => $table_id));

        if (!$deleted) {
            wp_send_json_error(array('message' => __('Could not delete table.', 'restaurant-booking')));
            wp_die();
        }

        wp_send_json_success(array('message' => __('Table deleted successfully.', 'restaurant-booking')));
        wp_die();
    }

    public function handle_manager_set_customer_vip() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        $status = isset($_POST['status']) ? intval($_POST['status']) : 0;

        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        global $wpdb;
        $customer_table = $wpdb->prefix . 'rb_customers';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customer_table WHERE id = %d", $customer_id));

        if (!$customer) {
            wp_send_json_error(array('message' => __('Customer not found.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->manager_can_access_location($permissions, $customer->location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this customer.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== (int) $customer->location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before making changes.', 'restaurant-booking')));
            wp_die();
        }

        $rb_customer->set_vip_status($customer_id, $status ? 1 : 0);

        wp_send_json_success(array('message' => __('Customer updated successfully.', 'restaurant-booking')));
        wp_die();
    }

    public function handle_manager_set_customer_blacklist() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        $status = isset($_POST['status']) ? intval($_POST['status']) : 0;

        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        global $wpdb;
        $customer_table = $wpdb->prefix . 'rb_customers';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customer_table WHERE id = %d", $customer_id));

        if (!$customer) {
            wp_send_json_error(array('message' => __('Customer not found.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->manager_can_access_location($permissions, $customer->location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this customer.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== (int) $customer->location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before making changes.', 'restaurant-booking')));
            wp_die();
        }

        $rb_customer->set_blacklist($customer_id, $status ? 1 : 0);

        wp_send_json_success(array('message' => __('Customer updated successfully.', 'restaurant-booking')));
        wp_die();
    }

    public function handle_manager_update_customer_note() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';

        if (!$customer_id) {
            wp_send_json_error(array('message' => __('Invalid customer.', 'restaurant-booking')));
            wp_die();
        }

        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer, $wpdb;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        $customer_table = $wpdb->prefix . 'rb_customers';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $customer_table WHERE id = %d", $customer_id));

        if (!$customer) {
            wp_send_json_error(array('message' => __('Customer not found.', 'restaurant-booking')));
            wp_die();
        }

        if (!$this->manager_can_access_location($permissions, $customer->location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this customer.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== (int) $customer->location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before making changes.', 'restaurant-booking')));
            wp_die();
        }

        $rb_customer->update_customer_notes($customer_id, $note);

        wp_send_json_success(array('message' => __('Customer note saved successfully.', 'restaurant-booking')));
        wp_die();
    }

    public function handle_manager_customer_history() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';

        if ($phone === '') {
            wp_send_json_error(array('message' => __('Phone number is required.', 'restaurant-booking')));
            wp_die();
        }

        if (!class_exists('RB_Customer')) {
            require_once RB_PLUGIN_DIR . 'includes/class-customer.php';
        }

        global $rb_customer;
        if (!$rb_customer) {
            $rb_customer = new RB_Customer();
        }

        $active_location = $this->get_permissions_active_location($permissions);

        $history = $rb_customer->get_customer_history($phone, $active_location ? $active_location : null);

        $formatted = array();
        foreach ($history as $booking) {
            $formatted[] = array(
                'booking_date' => $booking->booking_date,
                'booking_time' => $booking->booking_time,
                'guest_count' => $booking->guest_count,
                'table_number' => isset($booking->table_number) ? $booking->table_number : '',
                'status' => $booking->status,
            );
        }

        wp_send_json_success(array('history' => $formatted));
        wp_die();
    }

    public function handle_manager_update_settings() {
        $permissions = $this->get_current_manager_permissions();

        if (!$permissions) {
            wp_send_json_error(array('message' => __('You are not allowed to perform this action.', 'restaurant-booking')));
            wp_die();
        }

        if (!check_ajax_referer('rb_frontend_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed', 'restaurant-booking')));
            wp_die();
        }

        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if (!$this->manager_can_access_location($permissions, $location_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to manage this location.', 'restaurant-booking')));
            wp_die();
        }

        $active_location = $this->get_permissions_active_location($permissions);
        if ($active_location && $active_location !== $location_id) {
            wp_send_json_error(array('message' => __('Please switch to the correct location before making changes.', 'restaurant-booking')));
            wp_die();
        }

        if (!class_exists('RB_Location')) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
        }

        global $rb_location;
        if (!$rb_location) {
            $rb_location = new RB_Location();
        }

        $settings_input = isset($_POST['location_settings']) ? (array) wp_unslash($_POST['location_settings']) : array();
        $languages_input = isset($settings_input['languages']) ? (array) $settings_input['languages'] : array();
        $languages_allowed = function_exists('rb_get_available_languages') ? array_keys(rb_get_available_languages()) : array();
        $language_codes = array();

        foreach ($languages_input as $language_code) {
            $language_code = sanitize_text_field($language_code);
            if (empty($languages_allowed) || in_array($language_code, $languages_allowed, true)) {
                $language_codes[] = $language_code;
            }
        }

        $language_codes = array_values(array_unique($language_codes));

        $data = array(
            'hotline' => isset($settings_input['hotline']) ? sanitize_text_field($settings_input['hotline']) : '',
            'email' => isset($settings_input['email']) ? sanitize_email($settings_input['email']) : '',
            'address' => isset($settings_input['address']) ? sanitize_text_field($settings_input['address']) : '',
            'opening_time' => isset($settings_input['opening_time']) ? sanitize_text_field($settings_input['opening_time']) : '',
            'closing_time' => isset($settings_input['closing_time']) ? sanitize_text_field($settings_input['closing_time']) : '',
            'time_slot_interval' => isset($settings_input['time_slot_interval']) ? intval($settings_input['time_slot_interval']) : 30,
            'booking_buffer_time' => isset($settings_input['booking_buffer_time']) ? intval($settings_input['booking_buffer_time']) : 0,
            'min_advance_booking' => isset($settings_input['min_advance_booking']) ? intval($settings_input['min_advance_booking']) : 0,
            'max_advance_booking' => isset($settings_input['max_advance_booking']) ? intval($settings_input['max_advance_booking']) : 30,
            'max_guests_per_booking' => isset($settings_input['max_guests_per_booking']) ? intval($settings_input['max_guests_per_booking']) : 20,
            'shift_notes' => isset($settings_input['shift_notes']) ? sanitize_textarea_field($settings_input['shift_notes']) : '',
            'working_hours_mode' => isset($settings_input['working_hours_mode']) && $settings_input['working_hours_mode'] === 'advanced' ? 'advanced' : 'simple',
            'lunch_break_enabled' => !empty($settings_input['lunch_break_enabled']) ? 'yes' : 'no',
            'lunch_break_start' => isset($settings_input['lunch_break_start']) ? sanitize_text_field($settings_input['lunch_break_start']) : '',
            'lunch_break_end' => isset($settings_input['lunch_break_end']) ? sanitize_text_field($settings_input['lunch_break_end']) : '',
            'morning_shift_start' => isset($settings_input['morning_shift_start']) ? sanitize_text_field($settings_input['morning_shift_start']) : '',
            'morning_shift_end' => isset($settings_input['morning_shift_end']) ? sanitize_text_field($settings_input['morning_shift_end']) : '',
            'evening_shift_start' => isset($settings_input['evening_shift_start']) ? sanitize_text_field($settings_input['evening_shift_start']) : '',
            'evening_shift_end' => isset($settings_input['evening_shift_end']) ? sanitize_text_field($settings_input['evening_shift_end']) : '',
            'auto_confirm_enabled' => !empty($settings_input['auto_confirm_enabled']) ? 'yes' : 'no',
            'require_deposit' => !empty($settings_input['require_deposit']) ? 'yes' : 'no',
            'deposit_amount' => isset($settings_input['deposit_amount']) ? intval($settings_input['deposit_amount']) : 0,
            'deposit_for_guests' => isset($settings_input['deposit_for_guests']) ? intval($settings_input['deposit_for_guests']) : 0,
            'admin_email' => isset($settings_input['admin_email']) ? sanitize_email($settings_input['admin_email']) : '',
            'enable_email' => !empty($settings_input['enable_email']) ? 'yes' : 'no',
            'enable_sms' => !empty($settings_input['enable_sms']) ? 'yes' : 'no',
            'sms_api_key' => isset($settings_input['sms_api_key']) ? sanitize_text_field($settings_input['sms_api_key']) : '',
            'reminder_hours_before' => isset($settings_input['reminder_hours_before']) ? intval($settings_input['reminder_hours_before']) : 24,
            'special_closed_dates' => isset($settings_input['special_closed_dates']) ? sanitize_textarea_field($settings_input['special_closed_dates']) : '',
            'cancellation_hours' => isset($settings_input['cancellation_hours']) ? intval($settings_input['cancellation_hours']) : 2,
            'weekend_enabled' => !empty($settings_input['weekend_enabled']) ? 'yes' : 'no',
            'no_show_auto_blacklist' => isset($settings_input['no_show_auto_blacklist']) ? intval($settings_input['no_show_auto_blacklist']) : 0,
            'languages' => $language_codes,
        );

        if (!empty($data['email']) && !is_email($data['email'])) {
            wp_send_json_error(array('message' => __('Please enter a valid email address.', 'restaurant-booking')));
            wp_die();
        }

        if (!empty($data['admin_email']) && !is_email($data['admin_email'])) {
            wp_send_json_error(array('message' => __('Please enter a valid notification email.', 'restaurant-booking')));
            wp_die();
        }

        $updated = $rb_location->update_settings($location_id, $data);

        if ($updated === false) {
            wp_send_json_error(array('message' => __('Could not save settings. Please try again.', 'restaurant-booking')));
            wp_die();
        }

        wp_send_json_success(array('message' => __('Settings saved successfully.', 'restaurant-booking')));
        wp_die();
    }
}
