<?php
/**
 * Admin Class - Quản lý backend với Đa ngôn ngữ
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Admin {

    private $portal_account_manager;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('admin_notices', array($this, 'display_admin_notices'));

    }

    private function get_portal_account_manager() {
        if (!$this->portal_account_manager) {
            $this->portal_account_manager = RB_Portal_Account_Manager::get_instance();
        }

        return $this->portal_account_manager;
    }

    private function get_all_locations_for_portal_accounts() {
        global $rb_location;

        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        if (!$rb_location) {
            return array();
        }

        $locations = $rb_location->all();
        if (empty($locations)) {
            return array();
        }

        $formatted = array();
        foreach ($locations as $location) {
            $formatted[] = array(
                'id' => (int) $location->id,
                'name' => $location->name,
            );
        }

        return $formatted;
    }

    private function save_portal_account() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You are not allowed to manage portal accounts.', 'restaurant-booking'));
        }

        $account_id = isset($_POST['portal_account_id']) ? intval($_POST['portal_account_id']) : 0;
        $username = isset($_POST['portal_username']) ? sanitize_user(wp_unslash($_POST['portal_username']), true) : '';
        $display_name = isset($_POST['portal_display_name']) ? sanitize_text_field(wp_unslash($_POST['portal_display_name'])) : '';
        $email = isset($_POST['portal_email']) ? sanitize_email(wp_unslash($_POST['portal_email'])) : '';
        $status = isset($_POST['portal_status']) ? sanitize_text_field(wp_unslash($_POST['portal_status'])) : 'active';
        $password = isset($_POST['portal_password']) ? (string) wp_unslash($_POST['portal_password']) : '';
        $locations = isset($_POST['portal_locations']) ? array_map('intval', (array) wp_unslash($_POST['portal_locations'])) : array();
        $locations = array_values(array_unique(array_filter($locations)));

        $error = null;

        if (empty($locations)) {
            $error = new WP_Error('rb_missing_locations', __('Please assign at least one location to the portal account.', 'restaurant-booking'));
        } else {
            $manager = $this->get_portal_account_manager();
            $data = array(
                'username' => $username,
                'display_name' => $display_name,
                'email' => $email,
                'status' => $status === 'inactive' ? 'inactive' : 'active',
                'last_location_id' => !empty($locations) ? (int) $locations[0] : 0,
            );

            if (!empty($password)) {
                $data['password'] = $password;
            }

            if ($account_id) {
                $result = $manager->update_account($account_id, $data, $locations);
            } else {
                if (empty($password)) {
                    $result = new WP_Error('rb_missing_password', __('Password is required for new accounts.', 'restaurant-booking'));
                } else {
                    $result = $manager->create_account($data, $locations);
                }
            }

            if (is_wp_error($result)) {
                $error = $result;
            }
        }

        $redirect_args = array(
            'page' => 'rb-settings',
            'rb_tab' => 'portal-accounts',
        );

        if (!empty($error) && is_wp_error($error)) {
            $redirect_args['message'] = 'portal_account_error';
            $redirect_args['error'] = rawurlencode($error->get_error_message());
            if ($account_id) {
                $redirect_args['portal_account'] = $account_id;
            }
        } else {
            $redirect_args['message'] = 'portal_account_saved';
        }

        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

    private function delete_portal_account($account_id) {
        if (!current_user_can('manage_options')) {
            wp_die(__('You are not allowed to manage portal accounts.', 'restaurant-booking'));
        }

        $account_id = (int) $account_id;

        $manager = $this->get_portal_account_manager();
        $deleted = false;
        if ($account_id) {
            $deleted = $manager->delete_account($account_id);
        }

        $redirect_args = array(
            'page' => 'rb-settings',
            'rb_tab' => 'portal-accounts',
        );

        if ($deleted) {
            $redirect_args['message'] = 'portal_account_deleted';
        } else {
            $redirect_args['message'] = 'portal_account_error';
            $redirect_args['error'] = rawurlencode(__('Portal account could not be deleted.', 'restaurant-booking'));
        }

        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }
    
    public function add_admin_menu() {
        add_menu_page(
            rb_t('booking'),
            rb_t('booking'),
            'manage_options',
            'restaurant-booking',
            array($this, 'display_dashboard_page'),
            'dashicons-calendar-alt',
            30
        );
        
        add_submenu_page(
            'restaurant-booking',
            rb_t('dashboard'),
            rb_t('dashboard'),
            'manage_options',
            'restaurant-booking',
            array($this, 'display_dashboard_page')
        );
        
        add_submenu_page(
            'restaurant-booking',
            rb_t('create_booking'),
            rb_t('create_booking'),
            'manage_options',
            'rb-create-booking',
            array($this, 'display_create_booking_page')
        );
        
        add_submenu_page(
            'restaurant-booking',
            rb_t('manage_tables'),
            rb_t('manage_tables'),
            'manage_options',
            'rb-tables',
            array($this, 'display_tables_page')
        );
        
        add_submenu_page(
            'restaurant-booking',
            rb_t('manage_customers'),
            rb_t('customers'),
            'manage_options',
            'rb-customers',
            array($this, 'display_customers_page')
        );
        
        add_submenu_page(
            'restaurant-booking',
            rb_t('settings'),
            rb_t('settings'),
            'manage_options',
            'rb-settings',
            array($this, 'display_settings_page')
        );
    }
    
    public function display_create_booking_page() {
        global $wpdb, $rb_location;
        $settings = get_option('rb_settings', array());

        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $locations = $rb_location ? $rb_location->all() : array();

        if (empty($locations)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('No locations found. Please create at least one location before creating bookings.', 'restaurant-booking') . '</p></div>';
            return;
        }

        $selected_location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
        if (empty($selected_location_id)) {
            $selected_location_id = (int) $locations[0]->id;
        }

        $available_location_ids = array_map('intval', wp_list_pluck($locations, 'id'));
        if (!in_array($selected_location_id, $available_location_ids, true)) {
            $selected_location_id = (int) $locations[0]->id;
        }

        $location_settings = $rb_location->get_settings($selected_location_id);
        $location_details = $rb_location->get($selected_location_id);

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

        ?>
        <div class="wrap">
            <h1><?php rb_e('create_new_booking'); ?></h1>

            <div class="rb-location-switcher" style="margin: 20px 0;">
                <form method="get" action="" style="display: inline-flex; gap: 10px; align-items: center;">
                    <input type="hidden" name="page" value="rb-create-booking">
                    <label for="rb-admin-location" style="font-weight: 600;">
                        <?php esc_html_e('Location', 'restaurant-booking'); ?>
                    </label>
                    <select id="rb-admin-location" name="location_id" onchange="this.form.submit();">
                        <?php foreach ($locations as $location) : ?>
                            <option value="<?php echo esc_attr($location->id); ?>" <?php selected($selected_location_id, (int) $location->id); ?>>
                                <?php echo esc_html($location->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if ($location_details) : ?>
                    <div style="margin-top: 10px; color: #555;">
                        <strong><?php echo esc_html($location_details->name); ?></strong>
                        <?php if (!empty($location_details->address)) : ?>
                            <span style="margin-left: 8px;">
                                <?php echo esc_html($location_details->address); ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($location_details->hotline)) : ?>
                            <span style="margin-left: 8px;">
                                📞 <?php echo esc_html($location_details->hotline); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Language Switcher -->
            <div class="rb-admin-language-switcher" style="float: right; margin-top: -50px;">
                <?php
                if (class_exists('RB_Language_Switcher')) {
                    $switcher = new RB_Language_Switcher();
                    $switcher->render_dropdown();
                }
                ?>
            </div>
            
            <div class="card" style="max-width: 800px; clear: both;">
                <form method="post" action="" id="rb-admin-create-booking-form">
                    <?php wp_nonce_field('rb_create_admin_booking', 'rb_nonce'); ?>
                    <input type="hidden" name="action" value="create_admin_booking">
                    <input type="hidden" name="location_id" value="<?php echo esc_attr($selected_location_id); ?>">

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="customer_name"><?php rb_e('customer_name'); ?> *</label>
                            </th>
                            <td>
                                <input type="text" name="customer_name" id="customer_name" 
                                       class="regular-text" required>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="customer_phone"><?php rb_e('phone_number'); ?> *</label>
                            </th>
                            <td>
                                <input type="tel" name="customer_phone" id="customer_phone" 
                                       class="regular-text" required pattern="[0-9]{10,11}">
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="customer_email"><?php rb_e('email'); ?> *</label>
                            </th>
                            <td>
                                <input type="email" name="customer_email" id="customer_email" 
                                       class="regular-text" required>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="guest_count"><?php rb_e('number_of_guests'); ?> *</label>
                            </th>
                            <td>
                                <select name="guest_count" id="guest_count" required>
                                    <?php for ($i = 1; $i <= 20; $i++) : ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?> <?php rb_e('people'); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="booking_date"><?php rb_e('booking_date'); ?> *</label>
                            </th>
                            <td>
                                <input type="date" name="booking_date" id="booking_date"
                                       min="<?php echo esc_attr($min_date); ?>"
                                       max="<?php echo esc_attr($max_date); ?>" required>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="booking_time"><?php rb_e('booking_time'); ?> *</label>
                            </th>
                            <td>
                                <select name="booking_time" id="booking_time" required>
                                    <option value=""><?php rb_e('select_time'); ?></option>
                                    <?php foreach ($time_slots as $slot) : ?>
                                        <option value="<?php echo esc_attr($slot); ?>">
                                            <?php echo esc_html($slot); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="booking_source"><?php rb_e('booking_source'); ?> *</label>
                            </th>
                            <td>
                                <select name="booking_source" id="booking_source" required>
                                    <option value="phone">📞 <?php rb_e('phone'); ?></option>
                                    <option value="facebook">📘 <?php rb_e('facebook'); ?></option>
                                    <option value="zalo">💬 <?php rb_e('zalo'); ?></option>
                                    <option value="instagram">📷 <?php rb_e('instagram'); ?></option>
                                    <option value="walk-in">🚶 <?php rb_e('walk_in'); ?></option>
                                    <option value="email">✉️ <?php rb_e('email'); ?></option>
                                    <option value="other">❓ <?php rb_e('other'); ?></option>
                                </select>
                                <p class="description"><?php rb_e('select_booking_source_desc'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="special_requests"><?php rb_e('special_requests'); ?></label>
                            </th>
                            <td>
                                <textarea name="special_requests" id="special_requests" 
                                          rows="3" class="large-text"></textarea>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="admin_notes"><?php rb_e('admin_notes'); ?></label>
                            </th>
                            <td>
                                <textarea name="admin_notes" id="admin_notes" 
                                          rows="3" class="large-text"></textarea>
                                <p class="description"><?php rb_e('admin_notes_desc'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="auto_confirm"><?php rb_e('auto_confirm'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="auto_confirm" id="auto_confirm" value="1" checked>
                                    <?php rb_e('auto_confirm_desc'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php rb_e('create_booking'); ?></button>
                        <a href="?page=restaurant-booking" class="button"><?php rb_e('cancel'); ?></a>
                    </p>
                </form>
            </div>
            
            <div id="rb-availability-info" style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 3px; display: none;">
                <h3><?php rb_e('available_tables_info'); ?></h3>
                <div id="rb-available-tables-list"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#booking_date, #booking_time, #guest_count').on('change', function() {
                var date = $('#booking_date').val();
                var time = $('#booking_time').val();
                var guests = $('#guest_count').val();
                
                if (date && time && guests) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'rb_check_availability',
                            date: date,
                            time: time,
                            guests: guests,
                            nonce: '<?php echo wp_create_nonce("rb_frontend_nonce"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                var html = '<p><strong><?php rb_e('status'); ?>:</strong> ';
                                if (response.data.available) {
                                    html += '<span style="color: green;">✓ <?php rb_e('available'); ?></span></p>';
                                    html += '<p>' + response.data.message + '</p>';
                                } else {
                                    html += '<span style="color: red;">✗ <?php rb_e('not_available'); ?></span></p>';
                                    html += '<p>' + response.data.message + '</p>';
                                }
                                $('#rb-available-tables-list').html(html);
                                $('#rb-availability-info').show();
                            }
                        }
                    });
                }
            });
        });
        </script>
        <?php
    }
    
    public function display_dashboard_page() {
        global $wpdb, $rb_location;
        $table_name = $wpdb->prefix . 'rb_bookings';

        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $locations = $rb_location ? $rb_location->all() : array();
        $selected_location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;

        if (empty($selected_location_id) && !empty($locations)) {
            $selected_location_id = (int) $locations[0]->id;
        }

        $location_lookup = array();
        foreach ($locations as $location_item) {
            $location_lookup[(int) $location_item->id] = $location_item;
        }

        if ($selected_location_id && !isset($location_lookup[$selected_location_id])) {
            $selected_location_id = !empty($locations) ? (int) $locations[0]->id : 0;
        }

        $active_location = $selected_location_id && isset($location_lookup[$selected_location_id])
            ? $location_lookup[$selected_location_id]
            : (!empty($locations) ? $locations[0] : null);

        $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
        $filter_source = isset($_GET['filter_source']) ? sanitize_text_field($_GET['filter_source']) : '';
        $filter_date_from = isset($_GET['filter_date_from']) ? sanitize_text_field($_GET['filter_date_from']) : '';
        $filter_date_to = isset($_GET['filter_date_to']) ? sanitize_text_field($_GET['filter_date_to']) : '';
        $sort_by = isset($_GET['sort_by']) ? sanitize_text_field($_GET['sort_by']) : 'created_at';
        $sort_order = isset($_GET['sort_order']) ? sanitize_text_field($_GET['sort_order']) : 'DESC';

        $where_clauses = array('1=1');

        if ($selected_location_id) {
            $where_clauses[] = $wpdb->prepare('location_id = %d', $selected_location_id);
        }

        if (!empty($filter_status)) {
            $where_clauses[] = $wpdb->prepare("status = %s", $filter_status);
        }
        
        if (!empty($filter_source)) {
            $where_clauses[] = $wpdb->prepare("booking_source = %s", $filter_source);
        }

        if (!empty($filter_date_from) && !empty($filter_date_to)) {
            $where_clauses[] = $wpdb->prepare("booking_date BETWEEN %s AND %s", $filter_date_from, $filter_date_to);
        } elseif (!empty($filter_date_from)) {
            $where_clauses[] = $wpdb->prepare("booking_date >= %s", $filter_date_from);
        } elseif (!empty($filter_date_to)) {
            $where_clauses[] = $wpdb->prepare("booking_date <= %s", $filter_date_to);
        }

        $where = implode(' AND ', $where_clauses);
        
        $allowed_sort = array('id', 'customer_name', 'booking_date', 'booking_time', 'guest_count', 'status', 'booking_source', 'created_at');
        if (!in_array($sort_by, $allowed_sort)) {
            $sort_by = 'created_at';
        }
        
        $sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
        
        $bookings = $wpdb->get_results("SELECT * FROM $table_name WHERE $where ORDER BY $sort_by $sort_order");

        if ($selected_location_id) {
            $stats = array(
                'total' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE location_id = %d", $selected_location_id)),
                'pending' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = 'pending' AND location_id = %d", $selected_location_id)),
                'confirmed' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = 'confirmed' AND location_id = %d", $selected_location_id)),
                'completed' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = 'completed' AND location_id = %d", $selected_location_id)),
                'cancelled' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE status = 'cancelled' AND location_id = %d", $selected_location_id)),
                'today' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE booking_date = %s AND location_id = %d", date('Y-m-d'), $selected_location_id))
            );

            $source_stats = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT booking_source, COUNT(*) as count
                    FROM $table_name
                    WHERE location_id = %d
                    GROUP BY booking_source
                    ORDER BY count DESC",
                    $selected_location_id
                )
            );
        } else {
            $stats = array(
                'total' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
                'pending' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending'"),
                'confirmed' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'confirmed'"),
                'completed' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'completed'"),
                'cancelled' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'cancelled'"),
                'today' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE booking_date = %s", date('Y-m-d')))
            );

            $source_stats = $wpdb->get_results(
                "SELECT booking_source, COUNT(*) as count
                FROM $table_name
                GROUP BY booking_source
                ORDER BY count DESC"
            );
        }
        
        ?>
        <div class="wrap">
            <h1>
                <?php rb_e('dashboard'); ?> - <?php rb_e('manage_bookings'); ?>
                <a href="?page=rb-create-booking" class="page-title-action">
                    <?php rb_e('create_new_booking'); ?>
                </a>
            </h1>

            <!-- Language Switcher -->
            <div class="rb-admin-language-switcher" style="float: right; margin-top: -50px;">
                <?php
                if (class_exists('RB_Language_Switcher')) {
                    $switcher = new RB_Language_Switcher();
                    $switcher->render_dropdown();
                }
                ?>
            </div>

            <?php if ($active_location) : ?>
                <div class="rb-location-summary" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #2271b1; clear: both;">
                    <strong><?php esc_html_e('Viewing location:', 'restaurant-booking'); ?></strong>
                    <?php echo esc_html($active_location->name); ?>
                    <?php if (!empty($active_location->address)) : ?>
                        <span style="margin-left: 10px; color: #555;">
                            <?php echo esc_html($active_location->address); ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($active_location->hotline)) : ?>
                        <span style="margin-left: 10px; color: #555;">
                            📞 <?php echo esc_html($active_location->hotline); ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php elseif (empty($locations)) : ?>
                <div class="notice notice-warning" style="margin: 20px 0; clear: both;">
                    <p><?php esc_html_e('No locations found. Please configure at least one location before managing bookings.', 'restaurant-booking'); ?></p>
                </div>
            <?php endif; ?>

            <div class="rb-stats-grid" style="margin-bottom: 30px; clear: both;">
                <div class="rb-stat-box">
                    <h3><?php rb_e('total_bookings'); ?></h3>
                    <p class="rb-stat-number"><?php echo $stats['total']; ?></p>
                </div>
                
                <div class="rb-stat-box">
                    <h3><?php rb_e('pending'); ?></h3>
                    <p class="rb-stat-number" style="color: #f39c12;"><?php echo $stats['pending']; ?></p>
                </div>
                
                <div class="rb-stat-box">
                    <h3><?php rb_e('confirmed'); ?></h3>
                    <p class="rb-stat-number" style="color: #27ae60;"><?php echo $stats['confirmed']; ?></p>
                </div>
                
                <div class="rb-stat-box">
                    <h3><?php rb_e('completed'); ?></h3>
                    <p class="rb-stat-number" style="color: #2ecc71;"><?php echo $stats['completed']; ?></p>
                </div>
                
                <div class="rb-stat-box">
                    <h3><?php rb_e('cancelled'); ?></h3>
                    <p class="rb-stat-number" style="color: #e74c3c;"><?php echo $stats['cancelled']; ?></p>
                </div>
                
                <div class="rb-stat-box">
                    <h3><?php rb_e('bookings_today'); ?></h3>
                    <p class="rb-stat-number" style="color: #3498db;"><?php echo $stats['today']; ?></p>
                </div>
            </div>
            
            <div class="card" style="margin-bottom: 20px; padding: 15px;">
                <h2 style="margin-top: 0;"><?php rb_e('stats_by_source'); ?></h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
                    <?php foreach ($source_stats as $source) : ?>
                        <div style="background: #f9f9f9; padding: 10px; border-radius: 3px; text-align: center;">
                            <strong><?php echo $this->get_source_label($source->booking_source); ?></strong>
                            <div style="font-size: 24px; color: #2271b1;"><?php echo $source->count; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="rb-filters-section" style="background: white; padding: 20px; margin-bottom: 20px; border: 1px solid #ccd0d4; border-radius: 3px;">
                <h2 style="margin-top: 0;"><?php rb_e('filters_and_sorting'); ?></h2>
                <form method="get" action="" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: end;">
                    <input type="hidden" name="page" value="restaurant-booking">

                    <?php if (!empty($locations)) : ?>
                        <div style="flex: 1; min-width: 180px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                                <?php esc_html_e('Location', 'restaurant-booking'); ?>
                            </label>
                            <select name="location_id" style="width: 100%;" onchange="this.form.submit();">
                                <?php foreach ($locations as $location) : ?>
                                    <option value="<?php echo esc_attr($location->id); ?>" <?php selected($selected_location_id, (int) $location->id); ?>>
                                        <?php echo esc_html($location->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div style="flex: 1; min-width: 150px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                            <?php rb_e('status'); ?>
                        </label>
                        <select name="filter_status" style="width: 100%;">
                            <option value=""><?php rb_e('all'); ?></option>
                            <option value="pending" <?php selected($filter_status, 'pending'); ?>><?php rb_e('pending'); ?></option>
                            <option value="confirmed" <?php selected($filter_status, 'confirmed'); ?>><?php rb_e('confirmed'); ?></option>
                            <option value="completed" <?php selected($filter_status, 'completed'); ?>><?php rb_e('completed'); ?></option>
                            <option value="cancelled" <?php selected($filter_status, 'cancelled'); ?>><?php rb_e('cancelled'); ?></option>
                        </select>
                    </div>
                    
                    <div style="flex: 1; min-width: 150px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                            <?php rb_e('customer_source'); ?>
                        </label>
                        <select name="filter_source" style="width: 100%;">
                            <option value=""><?php rb_e('all'); ?></option>
                            <option value="website" <?php selected($filter_source, 'website'); ?>>🌐 <?php rb_e('website'); ?></option>
                            <option value="phone" <?php selected($filter_source, 'phone'); ?>>📞 <?php rb_e('phone'); ?></option>
                            <option value="facebook" <?php selected($filter_source, 'facebook'); ?>>📘 <?php rb_e('facebook'); ?></option>
                            <option value="zalo" <?php selected($filter_source, 'zalo'); ?>>💬 <?php rb_e('zalo'); ?></option>
                            <option value="instagram" <?php selected($filter_source, 'instagram'); ?>>📷 <?php rb_e('instagram'); ?></option>
                            <option value="walk-in" <?php selected($filter_source, 'walk-in'); ?>>🚶 <?php rb_e('walk_in'); ?></option>
                            <option value="email" <?php selected($filter_source, 'email'); ?>>✉️ <?php rb_e('email'); ?></option>
                            <option value="other" <?php selected($filter_source, 'other'); ?>>❓ <?php rb_e('other'); ?></option>
                        </select>
                    </div>

                    <div style="flex: 1; min-width: 150px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                            <?php rb_e('from_date'); ?>
                        </label>
                        <input type="date" name="filter_date_from" value="<?php echo esc_attr($filter_date_from); ?>" style="width: 100%;">
                    </div>

                    <div style="flex: 1; min-width: 150px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                            <?php rb_e('to_date'); ?>
                        </label>
                        <input type="date" name="filter_date_to" value="<?php echo esc_attr($filter_date_to); ?>" style="width: 100%;">
                    </div>

                    <div style="flex: 1; min-width: 150px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                            <?php rb_e('sort_by'); ?>
                        </label>
                        <select name="sort_by" style="width: 100%;">
                            <option value="created_at" <?php selected($sort_by, 'created_at'); ?>><?php rb_e('created_time'); ?></option>
                            <option value="booking_date" <?php selected($sort_by, 'booking_date'); ?>><?php rb_e('booking_date'); ?></option>
                            <option value="booking_time" <?php selected($sort_by, 'booking_time'); ?>><?php rb_e('booking_time'); ?></option>
                            <option value="customer_name" <?php selected($sort_by, 'customer_name'); ?>><?php rb_e('customer_name'); ?></option>
                            <option value="booking_source" <?php selected($sort_by, 'booking_source'); ?>><?php rb_e('customer_source'); ?></option>
                        </select>
                    </div>

                    <div style="flex: 1; min-width: 120px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                            <?php rb_e('order'); ?>
                        </label>
                        <select name="sort_order" style="width: 100%;">
                            <option value="DESC" <?php selected($sort_order, 'DESC'); ?>><?php rb_e('descending'); ?></option>
                            <option value="ASC" <?php selected($sort_order, 'ASC'); ?>><?php rb_e('ascending'); ?></option>
                        </select>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="button button-primary"><?php rb_e('apply'); ?></button>
                        <?php
                        $clear_filters_url = add_query_arg(
                            array(
                                'page' => 'restaurant-booking',
                                'location_id' => $selected_location_id
                            ),
                            admin_url('admin.php')
                        );
                        ?>
                        <a href="<?php echo esc_url($clear_filters_url); ?>" class="button"><?php rb_e('clear_filters'); ?></a>
                    </div>
                </form>
            </div>
           
            <p style="margin-bottom: 10px;">
                <strong><?php printf(rb_t('showing_results'), count($bookings)); ?></strong>
            </p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th><?php rb_e('customer'); ?></th>
                        <th><?php rb_e('phone'); ?></th>
                        <th><?php rb_e('date_time'); ?></th>
                        <th style="width: 80px;"><?php rb_e('guests'); ?></th>
                        <th style="width: 70px;"><?php rb_e('table'); ?></th>
                        <th style="width: 100px;"><?php rb_e('source'); ?></th>
                        <th style="width: 110px;"><?php rb_e('status'); ?></th>
                        <th style="width: 250px;"><?php rb_e('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($bookings) : ?>
                        <?php foreach ($bookings as $booking) : ?>
                            <tr>
                                <td><?php echo esc_html($booking->id); ?></td>
                                <td><strong><?php echo esc_html($booking->customer_name); ?></strong></td>
                                <td><?php echo esc_html($booking->customer_phone); ?></td>
                                <td>
                                    <strong><?php echo esc_html(date('d/m/Y', strtotime($booking->booking_date))); ?></strong><br>
                                    <span style="color: #666;"><?php echo esc_html($booking->booking_time); ?></span>
                                </td>
                                <td style="text-align: center;"><?php echo esc_html($booking->guest_count); ?></td>
                                <td style="text-align: center;">
                                    <?php echo $booking->table_number ? '<strong>' . rb_t('table') . ' ' . esc_html($booking->table_number) . '</strong>' : '-'; ?>
                                </td>
                                <td>
                                    <?php 
                                    $source = isset($booking->booking_source) ? $booking->booking_source : 'website';
                                    echo '<span style="font-size: 11px; padding: 2px 6px; background: #e8e8e8; border-radius: 3px;">' . 
                                         esc_html($this->get_source_label($source)) . '</span>';
                                    ?>
                                </td>
                                <td>
                                    <span class="rb-status rb-status-<?php echo esc_attr($booking->status); ?>">
                                        <?php echo $this->get_status_label($booking->status); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="rb-table-actions">
                                        <?php if ($booking->status == 'pending') : ?>
                                            <a href="?page=restaurant-booking&action=confirm&id=<?php echo $booking->id; ?>&_wpnonce=<?php echo wp_create_nonce('rb_action'); ?>"
                                            class="button button-primary button-small">
                                                <?php rb_e('confirm'); ?>
                                            </a>
                                            <a href="?page=restaurant-booking&action=cancel&id=<?php echo $booking->id; ?>&_wpnonce=<?php echo wp_create_nonce('rb_action'); ?>"
                                            class="button button-small">
                                                <?php rb_e('cancel'); ?>
                                            </a>
                                        <?php elseif ($booking->status == 'confirmed') : ?>
                                            <a href="?page=restaurant-booking&action=complete&id=<?php echo $booking->id; ?>&_wpnonce=<?php echo wp_create_nonce('rb_action'); ?>"
                                            class="button button-small">
                                                <?php rb_e('complete'); ?>
                                            </a>
                                            <a href="?page=restaurant-booking&action=cancel&id=<?php echo $booking->id; ?>&_wpnonce=<?php echo wp_create_nonce('rb_action'); ?>"
                                            class="button button-small">
                                                <?php rb_e('cancel'); ?>
                                            </a>
                                        <?php endif; ?>
                                        <a href="?page=restaurant-booking&action=delete&id=<?php echo $booking->id; ?>&_wpnonce=<?php echo wp_create_nonce('rb_action'); ?>"
                                        class="button button-small"
                                        onclick="return confirm('<?php echo esc_js(rb_t('delete_confirm')); ?>')">
                                            <?php rb_e('delete'); ?>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px;">
                                <p style="font-size: 16px; color: #666;">
                                    <?php rb_e('no_bookings'); ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <style>
            .rb-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 15px;
            }
            .rb-stat-box {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 3px;
                padding: 15px;
                text-align: center;
            }
            .rb-stat-box h3 {
                margin: 0 0 8px 0;
                color: #666;
                font-size: 13px;
                font-weight: 400;
                text-transform: uppercase;
            }
            .rb-stat-number {
                font-size: 32px;
                font-weight: 700;
                margin: 0;
            }
            .rb-status {
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                display: inline-block;
                text-transform: uppercase;}
            .rb-status-pending { background: #fef2c0; color: #973d00; }
            .rb-status-confirmed { background: #c6e1c6; color: #2e6e2e; }
            .rb-status-cancelled { background: #f5c6c6; color: #8a0000; }
            .rb-status-completed { background: #d4edda; color: #155724; }
        </style>
        <?php
    }
    
    public function display_tables_page() {
        global $wpdb, $rb_location;
        $table_name = $wpdb->prefix . 'rb_tables';

        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $locations = $rb_location ? $rb_location->all() : array();

        if (empty($locations)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('No locations found. Please configure a location before managing tables.', 'restaurant-booking') . '</p></div>';
            return;
        }

        $selected_location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
        if (!$selected_location_id) {
            $selected_location_id = (int) $locations[0]->id;
        }

        $location_lookup = array();
        foreach ($locations as $location_item) {
            $location_lookup[(int) $location_item->id] = $location_item;
        }

        if ($selected_location_id && !isset($location_lookup[$selected_location_id])) {
            $selected_location_id = (int) $locations[0]->id;
        }

        $active_location = isset($location_lookup[$selected_location_id]) ? $location_lookup[$selected_location_id] : $locations[0];

        $tables = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE location_id = %d ORDER BY table_number",
            $selected_location_id
        ));

        ?>
        <div class="wrap">
            <h1><?php rb_e('manage_tables'); ?></h1>

            <!-- Language Switcher -->
            <div class="rb-admin-language-switcher" style="float: right; margin-top: -50px;">
                <?php
                if (class_exists('RB_Language_Switcher')) {
                    $switcher = new RB_Language_Switcher();
                    $switcher->render_dropdown();
                }
                ?>
            </div>

            <div class="rb-location-switcher" style="margin: 20px 0; clear: both;">
                <form method="get" action="" style="display: inline-flex; gap: 10px; align-items: center;">
                    <input type="hidden" name="page" value="rb-tables">
                    <label for="rb-tables-location" style="font-weight: 600;">
                        <?php esc_html_e('Location', 'restaurant-booking'); ?>
                    </label>
                    <select id="rb-tables-location" name="location_id" onchange="this.form.submit();">
                        <?php foreach ($locations as $location) : ?>
                            <option value="<?php echo esc_attr($location->id); ?>" <?php selected($selected_location_id, (int) $location->id); ?>>
                                <?php echo esc_html($location->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <div style="margin-top: 10px; color: #555;">
                    <strong><?php echo esc_html($active_location->name); ?></strong>
                    <?php if (!empty($active_location->address)) : ?>
                        <span style="margin-left: 8px;"><?php echo esc_html($active_location->address); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($active_location->hotline)) : ?>
                        <span style="margin-left: 8px;">📞 <?php echo esc_html($active_location->hotline); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card" style="clear: both;">
                <h2><?php rb_e('add_new_table'); ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field('rb_add_table', 'rb_nonce'); ?>
                    <input type="hidden" name="action" value="add_table">
                    <input type="hidden" name="location_id" value="<?php echo esc_attr($selected_location_id); ?>">
                    <table class="form-table">
                        <tr>
                            <th><label for="table_number"><?php rb_e('table_number'); ?></label></th>
                            <td>
                                <input type="number" name="table_number" id="table_number" min="1" required class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="capacity"><?php rb_e('capacity'); ?></label></th>
                            <td>
                                <input type="number" name="capacity" id="capacity" min="1" max="20" required class="regular-text">
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php rb_e('add_table'); ?></button>
                    </p>
                </form>
            </div>
            
            <h2><?php rb_e('table_list'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php rb_e('table_number'); ?></th>
                        <th><?php rb_e('capacity'); ?></th>
                        <th><?php rb_e('status'); ?></th>
                        <th><?php rb_e('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tables) : ?>
                        <?php foreach ($tables as $table) : ?>
                            <tr>
                                <td><?php echo esc_html($table->table_number); ?></td>
                                <td><?php echo esc_html($table->capacity); ?> <?php rb_e('people'); ?></td>
                                <td>
                                    <?php if ($table->is_available) : ?>
                                        <span style="color: green;">✓ <?php rb_e('active'); ?></span>
                                    <?php else : ?>
                                        <span style="color: red;">✗ <?php rb_e('inactive'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button 
                                        class="rb-toggle-table button button-small"
                                        data-table-id="<?php echo $table->id; ?>"
                                        data-available="<?php echo $table->is_available ? '1' : '0'; ?>">
                                        <?php echo $table->is_available ? rb_t('deactivate') : rb_t('activate'); ?>
                                    </button>
                                    <a href="?page=rb-tables&action=delete_table&id=<?php echo $table->id; ?>&location_id=<?php echo esc_attr($selected_location_id); ?>&_wpnonce=<?php echo wp_create_nonce('rb_action'); ?>"
                                       class="button button-small"
                                       onclick="return confirm('<?php echo esc_js(rb_t('delete_table_confirm')); ?>')">
                                        <?php rb_e('delete'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="4" style="text-align: center;"><?php rb_e('no_tables'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public function display_customers_page() {
        global $wpdb, $rb_customer;
        $customer_table = $wpdb->prefix . 'rb_customers';
        
        // Get filters
        $filter_vip = isset($_GET['filter_vip']) ? sanitize_text_field($_GET['filter_vip']) : '';
        $filter_blacklist = isset($_GET['filter_blacklist']) ? sanitize_text_field($_GET['filter_blacklist']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'total_bookings';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'DESC';
        
        // Build query arguments
        $query_args = array(
            'search' => $search,
            'orderby' => $orderby,
            'order' => $order
        );
        
        if ($filter_vip === 'yes') {
            $query_args['vip_only'] = true;
        }
        
        if ($filter_blacklist !== '') {
            $query_args['blacklisted'] = $filter_blacklist === 'yes' ? 1 : 0;
        }
        
        // Get customers
        $customers = $rb_customer->get_customers($query_args);
        
        // Get stats
        $stats = $rb_customer->get_stats();
        $vip_suggestions = $rb_customer->get_vip_suggestions();
        $problematic = $rb_customer->get_problematic_customers();
        
        ?>
        <div class="wrap">
            <h1>
                <?php rb_e('manage_customers'); ?>
                <span class="subtitle" style="margin-left: 15px; color: #666; font-size: 14px;">
                    <?php rb_e('crm_subtitle'); ?>
                </span>
            </h1>
            
            <!-- Language Switcher -->
            <div class="rb-admin-language-switcher" style="float: right; margin-top: -50px;">
                <?php 
                if (class_exists('RB_Language_Switcher')) {
                    $switcher = new RB_Language_Switcher();
                    $switcher->render_dropdown();
                }
                ?>
            </div>
            
            <!-- Stats Dashboard -->
            <div class="rb-stats-grid" style="margin-bottom: 30px; clear: both;">
                <div class="rb-stat-box">
                    <h3><?php rb_e('total_customers'); ?></h3>
                    <p class="rb-stat-number"><?php echo $stats['total']; ?></p>
                </div>
                
                <div class="rb-stat-box">
                    <h3><?php rb_e('vip_customers'); ?></h3>
                    <p class="rb-stat-number" style="color: #f39c12;">⭐ <?php echo $stats['vip']; ?></p>
                </div>
                
                <div class="rb-stat-box">
                    <h3><?php rb_e('blacklisted'); ?></h3>
                    <p class="rb-stat-number" style="color: #e74c3c;">🚫 <?php echo $stats['blacklisted']; ?></p>
                </div>
                
                <div class="rb-stat-box">
                    <h3><?php rb_e('new_this_month'); ?></h3>
                    <p class="rb-stat-number" style="color: #3498db;">✨ <?php echo $stats['new_this_month']; ?></p>
                </div>
            </div>
            
            <!-- VIP Suggestions -->
            <?php if (!empty($vip_suggestions)) : ?>
            <div class="notice notice-info" style="margin-bottom: 20px;">
                <p><strong>💡 <?php rb_e('vip_upgrade_suggestions'); ?>:</strong> 
                    <?php printf(rb_t('vip_suggestions_count'), count($vip_suggestions)); ?>
                    <button type="button" class="button button-small" onclick="jQuery('#vip-suggestions').toggle()">
                        <?php rb_e('view_details'); ?>
                    </button>
                </p>
                <div id="vip-suggestions" style="display: none; margin-top: 10px;">
                    <?php foreach ($vip_suggestions as $sugg) : ?>
                        <div style="padding: 8px; background: #f9f9f9; margin: 5px 0; border-radius: 3px;">
                            <strong><?php echo esc_html($sugg->name); ?></strong> 
                            (<?php echo esc_html($sugg->phone); ?>) - 
                            <?php echo $sugg->completed_bookings; ?> <?php rb_e('completed_bookings'); ?>
                            <button class="button button-small rb-set-vip" data-customer-id="<?php echo $sugg->id; ?>">
                                <?php rb_e('set_vip'); ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Problematic Customers Warning -->
            <?php if (!empty($problematic)) : ?>
            <div class="notice notice-warning" style="margin-bottom: 20px;">
                <p><strong>⚠️ <?php rb_e('warning'); ?>:</strong> 
                    <?php printf(rb_t('problematic_customers_count'), count($problematic)); ?>
                    <button type="button" class="button button-small" onclick="jQuery('#problematic-list').toggle()">
                        <?php rb_e('view_details'); ?>
                    </button>
                </p>
                <div id="problematic-list" style="display: none; margin-top: 10px;">
                    <?php foreach ($problematic as $prob) : ?>
                        <div style="padding: 8px; background: #fff3cd; margin: 5px 0; border-radius: 3px;">
                            <strong><?php echo esc_html($prob->name); ?></strong> - 
                            <?php rb_e('problem_rate'); ?>: <?php echo round($prob->problem_rate, 1); ?>% 
                            (<?php echo $prob->problem_count; ?>/<?php echo $prob->total_bookings; ?>)
                            <button class="button button-small rb-blacklist" data-customer-id="<?php echo $prob->id; ?>">
                                <?php rb_e('blacklist'); ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Filters Section -->
            <div class="card" style="margin-bottom: 20px; padding: 15px;">
                <h3 style="margin-top: 0;">🔍 <?php rb_e('search_and_filter'); ?></h3>
                <form method="get" action="" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: end;">
                    <input type="hidden" name="page" value="rb-customers">
                    
                    <div style="flex: 2; min-width: 200px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;"><?php rb_e('search'); ?></label>
                        <input type="text" name="s" value="<?php echo esc_attr($search); ?>" 
                            placeholder="<?php echo esc_attr(rb_t('search_placeholder')); ?>" style="width: 100%;">
                    </div>
                    
                    <div style="flex: 1; min-width: 150px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;"><?php rb_e('vip'); ?></label>
                        <select name="filter_vip" style="width: 100%;">
                            <option value=""><?php rb_e('all'); ?></option>
                            <option value="yes" <?php selected($filter_vip, 'yes'); ?>><?php rb_e('vip_only'); ?></option>
                            <option value="no" <?php selected($filter_vip, 'no'); ?>><?php rb_e('non_vip'); ?></option>
                        </select>
                    </div>
                    
                    <div style="flex: 1; min-width: 150px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;"><?php rb_e('blacklist'); ?></label>
                        <select name="filter_blacklist" style="width: 100%;">
                            <option value=""><?php rb_e('all'); ?></option>
                            <option value="yes" <?php selected($filter_blacklist, 'yes'); ?>><?php rb_e('blacklisted'); ?></option>
                            <option value="no" <?php selected($filter_blacklist, 'no'); ?>><?php rb_e('normal'); ?></option>
                        </select>
                    </div>
                    
                    <div style="flex: 1; min-width: 150px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;"><?php rb_e('sort_by'); ?></label>
                        <select name="orderby" style="width: 100%;">
                            <option value="total_bookings" <?php selected($orderby, 'total_bookings'); ?>><?php rb_e('total_bookings'); ?></option>
                            <option value="completed_bookings" <?php selected($orderby, 'completed_bookings'); ?>><?php rb_e('completed'); ?></option>
                            <option value="last_visit" <?php selected($orderby, 'last_visit'); ?>><?php rb_e('last_visit'); ?></option>
                            <option value="first_visit" <?php selected($orderby, 'first_visit'); ?>><?php rb_e('first_visit'); ?></option>
                            <option value="name" <?php selected($orderby, 'name'); ?>><?php rb_e('name'); ?></option>
                        </select>
                    </div>
                    
                    <div style="flex: 1; min-width: 100px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: 600;"><?php rb_e('order'); ?></label>
                        <select name="order" style="width: 100%;">
                            <option value="DESC" <?php selected($order, 'DESC'); ?>><?php rb_e('descending'); ?></option>
                            <option value="ASC" <?php selected($order, 'ASC'); ?>><?php rb_e('ascending'); ?></option>
                        </select>
                    </div>
                    
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="button button-primary"><?php rb_e('apply'); ?></button>
                        <a href="?page=rb-customers" class="button"><?php rb_e('clear_filters'); ?></a>
                    </div>
                </form>
            </div>
            
            <!-- Results Count -->
            <p style="margin-bottom: 10px;">
                <strong><?php printf(rb_t('showing_results'), count($customers)); ?></strong>
            </p>
            
            <!-- Customers Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th><?php rb_e('customer_name'); ?></th>
                        <th><?php rb_e('phone'); ?></th>
                        <th><?php rb_e('email'); ?></th>
                        <th style="width: 80px;"><?php rb_e('total'); ?></th>
                        <th style="width: 80px;"><?php rb_e('completed'); ?></th>
                        <th style="width: 80px;"><?php rb_e('cancelled'); ?></th>
                        <th style="width: 80px;"><?php rb_e('no_show'); ?></th>
                        <th style="width: 100px;"><?php rb_e('last_visit'); ?></th>
                        <th style="width: 100px;"><?php rb_e('status'); ?></th>
                        <th style="width: 220px;"><?php esc_html_e('Notes', 'restaurant-booking'); ?></th>
                        <th style="width: 200px;"><?php rb_e('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($customers) : ?>
                        <?php foreach ($customers as $customer) : 
                            $success_rate = $customer->total_bookings > 0 
                                ? round(($customer->completed_bookings / $customer->total_bookings) * 100, 1) 
                                : 0;
                            $problem_rate = $customer->total_bookings > 0 
                                ? round((($customer->no_shows + $customer->cancelled_bookings) / $customer->total_bookings) * 100, 1) 
                                : 0;
                        ?>
                            <tr data-customer-id="<?php echo $customer->id; ?>">
                                <td><?php echo $customer->id; ?></td>
                                <td>
                                    <strong><?php echo esc_html($customer->name); ?></strong>
                                    <?php if ($customer->vip_status) : ?>
                                        <span style="color: gold; font-size: 16px;" title="VIP">⭐</span>
                                    <?php endif; ?>
                                    <?php if ($customer->blacklisted) : ?>
                                        <span style="color: red; font-size: 16px;" title="<?php rb_e('blacklisted'); ?>">🚫</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($customer->phone); ?></td>
                                <td><?php echo esc_html($customer->email); ?></td>
                                <td style="text-align: center;">
                                    <strong><?php echo $customer->total_bookings; ?></strong>
                                </td>
                                <td style="text-align: center; color: green;">
                                    <strong><?php echo $customer->completed_bookings; ?></strong>
                                    <br><small><?php echo $success_rate; ?>%</small>
                                </td>
                                <td style="text-align: center; color: orange;">
                                    <?php echo $customer->cancelled_bookings; ?>
                                </td>
                                <td style="text-align: center; color: red;">
                                    <?php echo $customer->no_shows; ?>
                                    <?php if ($problem_rate > 30) : ?>
                                        <br><small style="color: red;">⚠️ <?php echo $problem_rate; ?>%</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $customer->last_visit ? date('d/m/Y', strtotime($customer->last_visit)) : '-'; ?>
                                </td>
                                <td>
                                    <?php if ($customer->vip_status) : ?>
                                        <span class="rb-badge" style="background: #f39c12; color: white;"><?php rb_e('vip'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($customer->blacklisted) : ?>
                                        <span class="rb-badge" style="background: #e74c3c; color: white;"><?php rb_e('banned'); ?></span>
                                    <?php elseif ($problem_rate > 50) : ?>
                                        <span class="rb-badge" style="background: #ff6b6b; color: white;"><?php rb_e('problem'); ?></span>
                                    <?php elseif ($customer->completed_bookings >= 5) : ?>
                                        <span class="rb-badge" style="background: #27ae60; color: white;"><?php rb_e('loyal'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <textarea class="rb-admin-customer-note" data-customer-id="<?php echo esc_attr($customer->id); ?>" rows="3" placeholder="<?php echo esc_attr__('Add internal note...', 'restaurant-booking'); ?>"><?php echo esc_textarea($customer->customer_notes); ?></textarea>
                                    <div class="rb-admin-note-actions">
                                        <button type="button" class="button button-small rb-admin-save-note" data-customer-id="<?php echo esc_attr($customer->id); ?>"><?php esc_html_e('Save note', 'restaurant-booking'); ?></button>
                                        <span class="rb-admin-note-status" aria-live="polite"></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="rb-table-actions">
                                        <button class="button button-small rb-view-history"
                                                data-customer-id="<?php echo $customer->id; ?>"
                                                data-customer-phone="<?php echo esc_attr($customer->phone); ?>">
                                            <?php rb_e('history'); ?>
                                        </button>

                                        <?php if (!$customer->vip_status && $customer->completed_bookings >= 3) : ?>
                                            <button class="button button-small rb-set-vip"
                                                    data-customer-id="<?php echo $customer->id; ?>">
                                                <?php rb_e('set_vip'); ?>
                                            </button>
                                        <?php endif; ?>

                                        <?php if (!$customer->blacklisted && $problem_rate > 50) : ?>
                                            <button class="button button-small rb-blacklist"
                                                    data-customer-id="<?php echo $customer->id; ?>">
                                                <?php rb_e('blacklist'); ?>
                                            </button>
                                        <?php elseif ($customer->blacklisted) : ?>
                                            <button class="button button-small rb-unblacklist"
                                                    data-customer-id="<?php echo $customer->id; ?>">
                                                <?php rb_e('unblacklist'); ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="12" style="text-align: center; padding: 40px;">
                                <p style="font-size: 16px; color: #666;"><?php rb_e('no_customers'); ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Customer History Modal -->
        <div id="rb-customer-history-modal" class="rb-modal" style="display: none;">
            <div class="rb-modal-content" style="max-width: 800px;">
                <span class="rb-close">&times;</span>
                <h2><?php rb_e('booking_history'); ?></h2>
                <div id="rb-customer-history-content"></div>
            </div>
        </div>
        
        <style>
            .rb-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }

            .rb-admin-customer-note {
                width: 100%;
                min-height: 70px;
                resize: vertical;
                padding: 6px 8px;
                border-radius: 4px;
                border: 1px solid #d0d0d0;
                background: #fff;
            }

            .rb-admin-note-actions {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-top: 6px;
            }

            .rb-admin-note-status {
                font-size: 12px;
                color: #2ecc71;
            }

            .rb-admin-note-status.is-error {
                color: #e74c3c;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // View history
            $('.rb-view-history').on('click', function() {
                var phone = $(this).data('customer-phone');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'rb_get_customer_history',
                        phone: phone,
                        nonce: '<?php echo wp_create_nonce("rb_admin_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var html = '<table class="wp-list-table widefat">';
                            html += '<thead><tr><th><?php rb_e('date'); ?></th><th><?php rb_e('time'); ?></th><th><?php rb_e('guests'); ?></th><th><?php rb_e('table'); ?></th><th><?php rb_e('status'); ?></th></tr></thead>';
                            html += '<tbody>';
                            
                            $.each(response.data.history, function(i, booking) {
                                html += '<tr>';
                                html += '<td>' + booking.booking_date + '</td>';
                                html += '<td>' + booking.booking_time + '</td>';
                                html += '<td>' + booking.guest_count + '</td>';
                                html += '<td>' + (booking.table_number || '-') + '</td>';
                                html += '<td>' + booking.status + '</td>';
                                html += '</tr>';
                            });
                            
                            html += '</tbody></table>';
                            
                            $('#rb-customer-history-content').html(html);
                            $('#rb-customer-history-modal').show();
                        }
                    }
                });
            });
            
            // Close modal
            $('.rb-close').on('click', function() {
                $('#rb-customer-history-modal').hide();
            });
            
            // Set VIP
            $('.rb-set-vip').on('click', function() {
                var customerId = $(this).data('customer-id');
                if (confirm('<?php echo esc_js(rb_t('upgrade_to_vip_confirm')); ?>')) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'rb_set_customer_vip',
                            customer_id: customerId,
                            status: 1,
                            nonce: '<?php echo wp_create_nonce("rb_admin_nonce"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            }
                        }
                    });
                }
            });
            
            // Blacklist
            $('.rb-blacklist').on('click', function() {
                var customerId = $(this).data('customer-id');
                if (confirm('<?php echo esc_js(rb_t('blacklist_confirm')); ?>')) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'rb_set_customer_blacklist',
                            customer_id: customerId,
                            status: 1,
                            nonce: '<?php echo wp_create_nonce("rb_admin_nonce"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            }
                        }
                    });
                }
            });
            
            // Unblacklist
            $('.rb-unblacklist').on('click', function() {
                var customerId = $(this).data('customer-id');
                if (confirm('<?php echo esc_js(rb_t('unblacklist_confirm')); ?>')) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'rb_set_customer_blacklist',
                            customer_id: customerId,
                            status: 0,
                            nonce: '<?php echo wp_create_nonce("rb_admin_nonce"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            }
                        }
                    });
                }
            });

            // Save customer note
            $('.rb-admin-save-note').on('click', function() {
                var $button = $(this);
                var customerId = $button.data('customer-id');
                var $row = $button.closest('tr');
                var $textarea = $row.find('.rb-admin-customer-note');
                var $status = $row.find('.rb-admin-note-status');
                var note = $textarea.val();

                $status.removeClass('is-error').text('<?php echo esc_js(__('Saving...', 'restaurant-booking')); ?>');
                $button.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'restaurant-booking')); ?>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'rb_update_customer_note',
                        customer_id: customerId,
                        note: note,
                        nonce: '<?php echo wp_create_nonce("rb_admin_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var message = response.data && response.data.message ? response.data.message : '<?php echo esc_js(__('Customer note saved successfully.', 'restaurant-booking')); ?>';
                            $status.removeClass('is-error').text(message);
                        } else {
                            var errorMessage = response.data && response.data.message ? response.data.message : '<?php echo esc_js(__('Could not save note. Please try again.', 'restaurant-booking')); ?>';
                            $status.addClass('is-error').text(errorMessage);
                        }
                    },
                    error: function() {
                        $status.addClass('is-error').text('<?php echo esc_js(__('Could not save note. Please try again.', 'restaurant-booking')); ?>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php echo esc_js(__('Save note', 'restaurant-booking')); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function display_settings_page() {
        $settings = get_option('rb_settings', array());
        
        // Default values
        $defaults = array(
            'working_hours_mode' => 'simple',
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'lunch_break_enabled' => 'no',
            'lunch_break_start' => '14:00',
            'lunch_break_end' => '17:00',
            'morning_shift_start' => '09:00',
            'morning_shift_end' => '14:00',
            'evening_shift_start' => '17:00',
            'evening_shift_end' => '22:00',
            'time_slot_interval' => 30,
            'booking_buffer_time' => 0,
            'min_advance_booking' => 2,
            'max_advance_booking' => 30,
            'max_guests_per_booking' => 20,
            'auto_confirm_enabled' => 'no',
            'require_deposit' => 'no',
            'deposit_amount' => 100000,
            'deposit_for_guests' => 10,
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
        
        $settings = wp_parse_args($settings, $defaults);

        $active_tab = isset($_GET['rb_tab']) ? sanitize_key($_GET['rb_tab']) : 'language';
        $allowed_tabs = array('language', 'hours', 'booking', 'notifications', 'policies', 'advanced', 'portal-accounts');
        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = 'language';
        }

        $portal_manager = $this->get_portal_account_manager();
        $portal_accounts = $portal_manager->get_accounts();
        $editing_account = null;
        if (isset($_GET['portal_account'])) {
            $editing_account = $portal_manager->get_account((int) $_GET['portal_account']);
            if ($editing_account) {
                $active_tab = 'portal-accounts';
            }
        }

        $portal_locations = $this->get_all_locations_for_portal_accounts();
        $portal_location_map = array();
        foreach ($portal_locations as $portal_location) {
            $portal_location_map[$portal_location['id']] = $portal_location['name'];
        }
        ?>
        <div class="wrap">
            <h1>⚙️ <?php rb_e('settings'); ?> - <?php rb_e('restaurant_booking'); ?></h1>

            <form method="post" action="" id="rb-settings-form">
                <?php wp_nonce_field('rb_save_settings', 'rb_nonce'); ?>
                <input type="hidden" name="action" value="save_settings">

                <!-- Tab Navigation -->
                <h2 class="nav-tab-wrapper">
                    <a href="#tab-language" class="nav-tab <?php echo $active_tab === 'language' ? 'nav-tab-active' : ''; ?>">🌐 <?php rb_e('language'); ?></a>
                    <a href="#tab-hours" class="nav-tab <?php echo $active_tab === 'hours' ? 'nav-tab-active' : ''; ?>">🕐 <?php rb_e('working_hours'); ?></a>
                    <a href="#tab-booking" class="nav-tab <?php echo $active_tab === 'booking' ? 'nav-tab-active' : ''; ?>">📅 <?php rb_e('booking_settings'); ?></a>
                    <a href="#tab-notifications" class="nav-tab <?php echo $active_tab === 'notifications' ? 'nav-tab-active' : ''; ?>">🔔 <?php rb_e('notifications'); ?></a>
                    <a href="#tab-policies" class="nav-tab <?php echo $active_tab === 'policies' ? 'nav-tab-active' : ''; ?>">📋 <?php rb_e('policies'); ?></a>
                    <a href="#tab-advanced" class="nav-tab <?php echo $active_tab === 'advanced' ? 'nav-tab-active' : ''; ?>">🔧 <?php rb_e('advanced'); ?></a>
                    <a href="#tab-portal-accounts" class="nav-tab <?php echo $active_tab === 'portal-accounts' ? 'nav-tab-active' : ''; ?>">🧑‍💼 <?php esc_html_e('Portal Accounts', 'restaurant-booking'); ?></a>
                </h2>
                            
                <!-- ✅ NEW TAB: Language Settings -->
                <div id="tab-language" class="rb-tab-content" style="display: <?php echo $active_tab === 'language' ? 'block' : 'none'; ?>;">
                    <h2><?php rb_e('language_settings'); ?></h2>

                    <div class="rb-language-status-card">
                        <h3><?php rb_e('current_language'); ?></h3>
                        <div class="rb-current-lang-display">
                            <span class="rb-lang-flag"><?php echo $languages[$current_lang]['flag']; ?></span>
                            <span class="rb-lang-name"><?php echo $languages[$current_lang]['name']; ?></span>
                            <span class="rb-lang-code">(<?php echo $current_lang; ?>)</span>
                        </div>
                    </div>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="default_language"><?php rb_e('default_language'); ?></label>
                            </th>
                            <td>
                                <select name="rb_settings[default_language]" id="default_language" class="regular-text">
                                    <?php foreach ($languages as $code => $info) : ?>
                                        <option value="<?php echo esc_attr($code); ?>" <?php selected($settings['default_language'], $code); ?>>
                                            <?php echo $info['flag'] . ' ' . esc_html($info['name']); ?> (<?php echo $code; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php rb_e('default_language_desc'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php rb_e('switch_interface_language'); ?></label>
                            </th>
                            <td>
                                <div class="rb-language-switcher-admin">
                                    <?php 
                                    if (class_exists('RB_Language_Switcher')) {
                                        $switcher = new RB_Language_Switcher();
                                        $switcher->render_dropdown();
                                    }
                                    ?>
                                </div>
                                <p class="description"><?php rb_e('switch_interface_language_desc'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php rb_e('available_languages'); ?></label>
                            </th>
                            <td>
                                <div class="rb-available-languages-list">
                                    <?php foreach ($languages as $code => $info) : ?>
                                        <div class="rb-lang-item">
                                            <span class="rb-lang-flag"><?php echo $info['flag']; ?></span>
                                            <span class="rb-lang-name"><?php echo esc_html($info['name']); ?></span>
                                            <span class="rb-lang-code">(<?php echo $code; ?>)</span>
                                            <?php 
                                            $trans_file = RB_PLUGIN_DIR . 'languages/' . $code . '/translations.php';
                                            if (file_exists($trans_file)) {
                                                echo '<span class="rb-lang-status installed">✓ ' . rb_t('installed') . '</span>';
                                            } else {
                                                echo '<span class="rb-lang-status missing">✗ ' . rb_t('not_installed') . '</span>';
                                            }
                                            ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description"><?php rb_e('available_languages_desc'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php rb_e('reset_language'); ?></label>
                            </th>
                            <td>
                                <button type="button" class="button" id="rb-reset-language">
                                    🔄 <?php rb_e('reset_to_default'); ?>
                                </button>
                                <p class="description"><?php rb_e('reset_language_desc'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                <!-- Tab 1: Working Hours -->
                <div id="tab-hours" class="rb-tab-content" style="display: <?php echo $active_tab === 'hours' ? 'block' : 'none'; ?>;">
                    <h2><?php rb_e('restaurant_working_hours'); ?></h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php rb_e('settings_mode'); ?></th>
                            <td>
                                <label>
                                    <input type="radio" name="rb_settings[working_hours_mode]" value="simple" 
                                        <?php checked($settings['working_hours_mode'], 'simple'); ?>>
                                    <strong><?php rb_e('simple_mode'); ?></strong> - <?php rb_e('simple_mode_desc'); ?>
                                </label>
                                <br>
                                <label style="margin-top: 10px; display: inline-block;">
                                    <input type="radio" name="rb_settings[working_hours_mode]" value="advanced" 
                                        <?php checked($settings['working_hours_mode'], 'advanced'); ?>>
                                    <strong><?php rb_e('advanced_mode'); ?></strong> - <?php rb_e('advanced_mode_desc'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- Simple Mode -->
                    <div id="simple-hours-section" style="display: <?php echo $settings['working_hours_mode'] == 'simple' ? 'block' : 'none'; ?>">
                        <h3><?php rb_e('simple_hours_settings'); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="opening_time"><?php rb_e('opening_time'); ?></label>
                                </th>
                                <td>
                                    <input type="time" name="rb_settings[opening_time]" id="opening_time" 
                                        value="<?php echo esc_attr($settings['opening_time']); ?>" class="regular-text">
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="closing_time"><?php rb_e('closing_time'); ?></label>
                                </th>
                                <td>
                                    <input type="time" name="rb_settings[closing_time]" id="closing_time" 
                                        value="<?php echo esc_attr($settings['closing_time']); ?>" class="regular-text">
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="lunch_break_enabled"><?php rb_e('has_lunch_break'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="rb_settings[lunch_break_enabled]" id="lunch_break_enabled" 
                                            value="yes" <?php checked($settings['lunch_break_enabled'], 'yes'); ?>>
                                        <?php rb_e('restaurant_has_lunch_break'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                        
                        <div id="lunch-break-times" style="display: <?php echo $settings['lunch_break_enabled'] == 'yes' ? 'block' : 'none'; ?>; margin-left: 30px;">
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php rb_e('lunch_break_start'); ?></th>
                                    <td>
                                        <input type="time" name="rb_settings[lunch_break_start]" 
                                            value="<?php echo esc_attr($settings['lunch_break_start']); ?>" class="regular-text">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php rb_e('lunch_break_end'); ?></th>
                                    <td>
                                        <input type="time" name="rb_settings[lunch_break_end]" 
                                            value="<?php echo esc_attr($settings['lunch_break_end']); ?>" class="regular-text">
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Advanced Mode -->
                    <div id="advanced-hours-section" style="display: <?php echo $settings['working_hours_mode'] == 'advanced' ? 'block' : 'none'; ?>">
                        <h3><?php rb_e('two_shifts_settings'); ?></h3>
                        
                        <div style="background: #f0f0f1; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                            <h4 style="margin-top: 0;">🌅 <?php rb_e('morning_shift'); ?></h4>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php rb_e('morning_shift_start'); ?></th>
                                    <td>
                                        <input type="time" name="rb_settings[morning_shift_start]" 
                                            value="<?php echo esc_attr($settings['morning_shift_start']); ?>" class="regular-text">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php rb_e('morning_shift_end'); ?></th>
                                    <td>
                                        <input type="time" name="rb_settings[morning_shift_end]" 
                                            value="<?php echo esc_attr($settings['morning_shift_end']); ?>" class="regular-text">
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <div style="background: #f0f0f1; padding: 15px; border-radius: 5px;">
                            <h4 style="margin-top: 0;">🌙 <?php rb_e('evening_shift'); ?></h4>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php rb_e('evening_shift_start'); ?></th>
                                    <td>
                                        <input type="time" name="rb_settings[evening_shift_start]" 
                                            value="<?php echo esc_attr($settings['evening_shift_start']); ?>" class="regular-text">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php rb_e('evening_shift_end'); ?></th>
                                    <td>
                                        <input type="time" name="rb_settings[evening_shift_end]" 
                                            value="<?php echo esc_attr($settings['evening_shift_end']); ?>" class="regular-text">
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="weekend_enabled"><?php rb_e('open_on_weekends'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="rb_settings[weekend_enabled]" id="weekend_enabled" 
                                        value="yes" <?php checked($settings['weekend_enabled'], 'yes'); ?>>
                                    <?php rb_e('accept_bookings_weekend'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Tab 2: Booking Settings -->
                <div id="tab-booking" class="rb-tab-content" style="display: <?php echo $active_tab === 'booking' ? 'block' : 'none'; ?>;">
                    <h2><?php rb_e('booking_settings'); ?></h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="time_slot_interval"><?php rb_e('time_slot_interval'); ?></label>
                            </th>
                            <td>
                                <select name="rb_settings[time_slot_interval]" id="time_slot_interval">
                                    <option value="15" <?php selected($settings['time_slot_interval'], 15); ?>>15 <?php rb_e('minutes'); ?></option>
                                    <option value="30" <?php selected($settings['time_slot_interval'], 30); ?>>30 <?php rb_e('minutes'); ?></option>
                                    <option value="45" <?php selected($settings['time_slot_interval'], 45); ?>>45 <?php rb_e('minutes'); ?></option>
                                    <option value="60" <?php selected($settings['time_slot_interval'], 60); ?>>60 <?php rb_e('minutes'); ?></option>
                                </select>
                                <p class="description"><?php rb_e('time_slot_interval_desc'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="booking_buffer_time"><?php rb_e('buffer_time'); ?></label>
                            </th>
                            <td>
                                <select name="rb_settings[booking_buffer_time]" id="booking_buffer_time">
                                    <option value="0" <?php selected($settings['booking_buffer_time'], 0); ?>><?php rb_e('none'); ?></option>
                                    <option value="15" <?php selected($settings['booking_buffer_time'], 15); ?>>15 <?php rb_e('minutes'); ?></option>
                                    <option value="30" <?php selected($settings['booking_buffer_time'], 30); ?>>30 <?php rb_e('minutes'); ?></option>
                                </select>
                                <p class="description"><?php rb_e('buffer_time_desc'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="min_advance_booking"><?php rb_e('min_advance_booking'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="rb_settings[min_advance_booking]" id="min_advance_booking" 
                                    value="<?php echo esc_attr($settings['min_advance_booking']); ?>" min="0" max="48" class="small-text"> <?php rb_e('hours'); ?>
                                <p class="description"><?php rb_e('min_advance_booking_desc'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="max_advance_booking"><?php rb_e('max_advance_booking'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="rb_settings[max_advance_booking]" id="max_advance_booking" 
                                    value="<?php echo esc_attr($settings['max_advance_booking']); ?>" min="1" max="90" class="small-text"> <?php rb_e('days'); ?>
                                <p class="description"><?php rb_e('max_advance_booking_desc'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="max_guests_per_booking"><?php rb_e('max_guests'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="rb_settings[max_guests_per_booking]" id="max_guests_per_booking" 
                                    value="<?php echo esc_attr($settings['max_guests_per_booking']); ?>" min="1" max="100" class="small-text"> <?php rb_e('people'); ?>
                                <p class="description"><?php rb_e('max_guests_desc'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="auto_confirm_enabled"><?php rb_e('auto_confirm'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="rb_settings[auto_confirm_enabled]" id="auto_confirm_enabled" 
                                        value="yes" <?php checked($settings['auto_confirm_enabled'], 'yes'); ?>>
                                    <?php rb_e('auto_confirm_desc'); ?>
                                </label>
                                <p class="description"><?php rb_e('auto_confirm_note'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Tab 3: Notifications -->
                <div id="tab-notifications" class="rb-tab-content" style="display: <?php echo $active_tab === 'notifications' ? 'block' : 'none'; ?>;">
                    <h2><?php rb_e('notification_settings'); ?></h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="admin_email"><?php rb_e('admin_email'); ?></label>
                            </th>
                            <td>
                                <input type="email" name="rb_settings[admin_email]" id="admin_email" 
                                    value="<?php echo esc_attr($settings['admin_email']); ?>" class="regular-text">
                                <p class="description"><?php rb_e('admin_email_desc'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php rb_e('customer_email'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="rb_settings[enable_email]" value="yes" 
                                        <?php checked($settings['enable_email'], 'yes'); ?>>
                                    <?php rb_e('send_confirmation_email'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="reminder_hours_before"><?php rb_e('reminder_before'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="rb_settings[reminder_hours_before]" id="reminder_hours_before" 
                                    value="<?php echo esc_attr($settings['reminder_hours_before']); ?>" min="1" max="72" class="small-text"> <?php rb_e('hours'); ?>
                                <p class="description"><?php rb_e('reminder_before_desc'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php rb_e('sms_notification'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="rb_settings[enable_sms]" value="yes" 
                                        <?php checked($settings['enable_sms'], 'yes'); ?>>
                                    <?php rb_e('enable_sms_notifications'); ?>
                                </label>
                                <br><br>
                                <input type="text" name="rb_settings[sms_api_key]" placeholder="<?php echo esc_attr(rb_t('enter_sms_api_key')); ?>" 
                                    value="<?php echo esc_attr($settings['sms_api_key']); ?>" class="regular-text">
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Tab 4: Policies -->
                <div id="tab-policies" class="rb-tab-content" style="display: <?php echo $active_tab === 'policies' ? 'block' : 'none'; ?>;">
                    <h2><?php rb_e('policies_and_rules'); ?></h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="require_deposit"><?php rb_e('require_deposit'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="rb_settings[require_deposit]" id="require_deposit" 
                                        value="yes" <?php checked($settings['require_deposit'], 'yes'); ?>>
                                    <?php rb_e('require_customer_deposit'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr id="deposit-settings" style="display: <?php echo $settings['require_deposit'] == 'yes' ? 'table-row' : 'none'; ?>;">
                            <th scope="row"><?php rb_e('deposit_details'); ?></th>
                            <td>
                                <label>
                                    <?php rb_e('deposit_amount'); ?>: 
                                    <input type="number" name="rb_settings[deposit_amount]" 
                                        value="<?php echo esc_attr($settings['deposit_amount']); ?>" class="regular-text"> VNĐ
                                </label>
                                <br><br>
                                <label>
                                    <?php rb_e('apply_for_bookings_from'); ?>: 
                                    <input type="number" name="rb_settings[deposit_for_guests]" 
                                        value="<?php echo esc_attr($settings['deposit_for_guests']); ?>" class="small-text"> <?php rb_e('guests_or_more'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="cancellation_hours"><?php rb_e('free_cancellation_before'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="rb_settings[cancellation_hours]" id="cancellation_hours" 
                                    value="<?php echo esc_attr($settings['cancellation_hours']); ?>" min="0" max="48" class="small-text"> <?php rb_e('hours'); ?>
                                <p class="description"><?php rb_e('cancellation_hours_desc'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="no_show_auto_blacklist"><?php rb_e('auto_blacklist_no_show'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="rb_settings[no_show_auto_blacklist]" id="no_show_auto_blacklist" 
                                    value="<?php echo esc_attr($settings['no_show_auto_blacklist']); ?>" min="1" max="10" class="small-text"> <?php rb_e('times'); ?>
                                <p class="description"><?php rb_e('auto_blacklist_desc'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="special_closed_dates"><?php rb_e('special_closed_dates'); ?></label>
                            </th>
                            <td>
                                <textarea name="rb_settings[special_closed_dates]" id="special_closed_dates" 
                                    rows="4" class="large-text" placeholder="<?php echo esc_attr(rb_t('special_closed_dates_placeholder')); ?>"><?php echo esc_textarea($settings['special_closed_dates']); ?></textarea>
                                <p class="description"><?php rb_e('special_closed_dates_desc'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Tab 5: Advanced -->
                <div id="tab-advanced" class="rb-tab-content" style="display: <?php echo $active_tab === 'advanced' ? 'block' : 'none'; ?>;">
                    <h2><?php rb_e('advanced_settings'); ?></h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php rb_e('database_cleanup'); ?></th>
                            <td>
                                <button type="button" class="button" id="cleanup-old-bookings">
                                    🗑️ <?php rb_e('delete_old_bookings'); ?>
                                </button>
                                <p class="description"><?php rb_e('cleanup_old_data_desc'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php rb_e('export_data'); ?></th>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=rb-settings&action=export_csv'); ?>" class="button">
                                    📊 <?php rb_e('export_all_bookings_csv'); ?>
                                </a>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php rb_e('reset_plugin'); ?></th>
                            <td>
                                <button type="button" class="button button-secondary" id="reset-plugin" 
                                    style="border-color: #dc3545; color: #dc3545;">
                                    ⚠️ <?php rb_e('reset_all_data'); ?>
                                </button>
                                <p class="description" style="color: #dc3545;">
                                    <strong><?php rb_e('warning'); ?>:</strong> <?php rb_e('reset_warning'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="rb-settings-submit-wrapper">
                    <p class="submit">
                        <button type="submit" class="button button-primary button-large">💾 <?php rb_e('save_all_settings'); ?></button>
                    </p>
                </div>
            </form>

            <div id="tab-portal-accounts" class="rb-tab-content" style="display: <?php echo $active_tab === 'portal-accounts' ? 'block' : 'none'; ?>;">
                <h2><?php esc_html_e('Portal Accounts', 'restaurant-booking'); ?></h2>
                <p class="description"><?php esc_html_e('Create dedicated logins for branch managers without giving access to the WordPress dashboard.', 'restaurant-booking'); ?></p>

                <?php if (empty($portal_locations)) : ?>
                    <div class="notice notice-warning">
                    <p><?php esc_html_e('Please create at least one location before adding portal accounts.', 'restaurant-booking'); ?></p>
                </div>
            <?php else : ?>
                <form method="post" action="">
                    <?php wp_nonce_field('rb_save_portal_account', 'rb_nonce'); ?>
                    <input type="hidden" name="action" value="save_portal_account">
                    <input type="hidden" name="portal_account_id" value="<?php echo esc_attr($editing_account ? $editing_account->id : 0); ?>">

                    <h3>
                        <?php echo $editing_account ? esc_html__('Update account', 'restaurant-booking') : esc_html__('Create new account', 'restaurant-booking'); ?>
                    </h3>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="portal_username"><?php esc_html_e('Username', 'restaurant-booking'); ?></label></th>
                            <td>
                                <input type="text" name="portal_username" id="portal_username" class="regular-text" value="<?php echo esc_attr($editing_account ? $editing_account->username : ''); ?>" required>
                                <p class="description"><?php esc_html_e('Usernames must be unique across portal accounts.', 'restaurant-booking'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="portal_display_name"><?php esc_html_e('Display name', 'restaurant-booking'); ?></label></th>
                            <td>
                                <input type="text" name="portal_display_name" id="portal_display_name" class="regular-text" value="<?php echo esc_attr($editing_account ? $editing_account->display_name : ''); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="portal_email"><?php esc_html_e('Email (optional)', 'restaurant-booking'); ?></label></th>
                            <td>
                                <input type="email" name="portal_email" id="portal_email" class="regular-text" value="<?php echo esc_attr($editing_account ? $editing_account->email : ''); ?>">
                                <p class="description"><?php esc_html_e('Use the email to allow sign-in with email address and send password resets in the future.', 'restaurant-booking'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="portal_password"><?php esc_html_e('Password', 'restaurant-booking'); ?></label></th>
                            <td>
                                <input type="password" name="portal_password" id="portal_password" class="regular-text" <?php echo $editing_account ? '' : 'required'; ?>>
                                <p class="description"><?php echo $editing_account ? esc_html__('Leave blank to keep the current password.', 'restaurant-booking') : esc_html__('Set an initial password for the new account.', 'restaurant-booking'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="portal_status"><?php esc_html_e('Status', 'restaurant-booking'); ?></label></th>
                            <td>
                                <select name="portal_status" id="portal_status">
                                    <option value="active" <?php selected(!$editing_account || $editing_account->status === 'active'); ?>><?php esc_html_e('Active', 'restaurant-booking'); ?></option>
                                    <option value="inactive" <?php selected($editing_account && $editing_account->status === 'inactive'); ?>><?php esc_html_e('Inactive', 'restaurant-booking'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="portal_locations"><?php esc_html_e('Allowed locations', 'restaurant-booking'); ?></label></th>
                            <td>
                                <select name="portal_locations[]" id="portal_locations" multiple size="<?php echo esc_attr(min(8, max(3, count($portal_locations)))); ?>" style="min-width: 260px;">
                                    <?php
                                    $selected_locations = $editing_account ? (array) $editing_account->locations : array();
                                    foreach ($portal_locations as $location) :
                                        ?>
                                        <option value="<?php echo esc_attr($location['id']); ?>" <?php selected(in_array($location['id'], $selected_locations, true)); ?>><?php echo esc_html($location['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Select the branches this account can manage. Hold Ctrl (Windows) or Command (macOS) to select multiple locations.', 'restaurant-booking'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php echo $editing_account ? esc_html__('Save changes', 'restaurant-booking') : esc_html__('Create account', 'restaurant-booking'); ?>
                        </button>
                    </p>
                </form>
            <?php endif; ?>

            <hr>

            <h3><?php esc_html_e('Existing accounts', 'restaurant-booking'); ?></h3>

            <?php if (empty($portal_accounts)) : ?>
                <p><?php esc_html_e('No portal accounts have been created yet.', 'restaurant-booking'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Username', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Display name', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Email', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Locations', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Status', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Last login', 'restaurant-booking'); ?></th>
                            <th><?php esc_html_e('Actions', 'restaurant-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($portal_accounts as $account) : ?>
                            <?php
                            $account_locations = array();
                            if (!empty($account->locations)) {
                                foreach ($account->locations as $location_id) {
                                    $account_locations[] = isset($portal_location_map[$location_id]) ? $portal_location_map[$location_id] : sprintf(__('Location #%d', 'restaurant-booking'), $location_id);
                                }
                            }
                            $edit_url = add_query_arg(
                                array(
                                    'page' => 'rb-settings',
                                    'rb_tab' => 'portal-accounts',
                                    'portal_account' => $account->id,
                                ),
                                admin_url('admin.php')
                            );
                            $delete_url = wp_nonce_url(
                                add_query_arg(
                                    array(
                                        'page' => 'rb-settings',
                                        'action' => 'delete_portal_account',
                                        'id' => $account->id,
                                        'rb_tab' => 'portal-accounts',
                                    ),
                                    admin_url('admin.php')
                                ),
                                'rb_action'
                            );
                            ?>
                            <tr>
                                <td><?php echo esc_html($account->username); ?></td>
                                <td><?php echo esc_html($account->display_name); ?></td>
                                <td><?php echo esc_html($account->email); ?></td>
                                <td><?php echo !empty($account_locations) ? esc_html(implode(', ', $account_locations)) : '—'; ?></td>
                                <td>
                                    <?php if ($account->status === 'active') : ?>
                                        <span class="rb-badge" style="background:#d4edda;color:#155724;"><?php esc_html_e('Active', 'restaurant-booking'); ?></span>
                                    <?php else : ?>
                                        <span class="rb-badge" style="background:#f8d7da;color:#721c24;"><?php esc_html_e('Inactive', 'restaurant-booking'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo !empty($account->last_login_at) ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($account->last_login_at))) : '—'; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($edit_url); ?>" class="button button-small"><?php esc_html_e('Edit', 'restaurant-booking'); ?></a>
                                    <a href="<?php echo esc_url($delete_url); ?>" class="button button-small delete" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this portal account?', 'restaurant-booking')); ?>');"><?php esc_html_e('Delete', 'restaurant-booking'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <style>
            .rb-tab-content {
                background: white;
                padding: 20px;
                border: 1px solid #ccd0d4;
                border-top: none;
            }
            .nav-tab-wrapper {
                margin-bottom: 0;
            }
               .rb-language-status-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 5px;
                padding: 20px;
                margin-bottom: 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            }

            .rb-language-status-card h3 {
                margin-top: 0;
                color: #2271b1;
            }

            .rb-current-lang-display {
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 18px;
                padding: 15px;
                background: #f0f6fc;
                border-radius: 5px;
                border-left: 4px solid #2271b1;
            }

            .rb-current-lang-display .rb-lang-flag {
                font-size: 32px;
            }

            .rb-current-lang-display .rb-lang-name {
                font-weight: 600;
                color: #2271b1;
            }

            .rb-current-lang-display .rb-lang-code {
                color: #666;
                font-size: 14px;
            }

            .rb-language-switcher-admin {
                display: inline-block;
                background: #f0f6fc;
                padding: 10px 15px;
                border-radius: 5px;
                border: 1px solid #2271b1;
            }

            .rb-available-languages-list {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 10px;
                margin-top: 10px;
            }

            .rb-lang-item {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 10px;
                background: #f9f9f9;
                border-radius: 3px;
                border: 1px solid #ddd;
            }

            .rb-lang-item .rb-lang-flag {
                font-size: 24px;
            }

            .rb-lang-item .rb-lang-name {
                font-weight: 600;
                flex: 1;
            }

            .rb-lang-item .rb-lang-code {
                color: #666;
                font-size: 12px;
            }

            .rb-lang-status {
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
            }

            .rb-lang-status.installed {
                background: #d4edda;
                color: #155724;
            }

            .rb-lang-status.missing {
                background: #f8d7da;
                color: #721c24;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            function rbShowTab(target) {
                $('.nav-tab').removeClass('nav-tab-active');
                $('.nav-tab[href="' + target + '"]').addClass('nav-tab-active');
                $('.rb-tab-content').hide();
                $(target).show();

                if (target === '#tab-portal-accounts') {
                    $('.rb-settings-submit-wrapper').hide();
                } else {
                    $('.rb-settings-submit-wrapper').show();
                }
            }

            // Tab switching
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                rbShowTab($(this).attr('href'));
            });

            rbShowTab('#tab-<?php echo esc_js($active_tab); ?>');

            // Toggle working hours mode
            $('input[name="rb_settings[working_hours_mode]"]').on('change', function() {
                if ($(this).val() === 'simple') {
                    $('#simple-hours-section').show();
                    $('#advanced-hours-section').hide();
                } else {
                    $('#simple-hours-section').hide();
                    $('#advanced-hours-section').show();
                }
            });
            
            // Toggle lunch break
            $('#lunch_break_enabled').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#lunch-break-times').show();
                } else {
                    $('#lunch-break-times').hide();
                }
            });
            
            // Toggle deposit settings
            $('#require_deposit').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#deposit-settings').show();
                } else {
                    $('#deposit-settings').hide();
                }
            });
            
            // Cleanup old bookings
            $('#cleanup-old-bookings').on('click', function() {
                if (confirm('<?php echo esc_js(rb_t('cleanup_confirm')); ?>')) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'rb_cleanup_old_bookings',
                            nonce: '<?php echo wp_create_nonce("rb_admin_nonce"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('<?php echo esc_js(rb_t('deleted')); ?> ' + response.data.deleted + ' <?php echo esc_js(rb_t('old_bookings')); ?>');
                            }
                        }
                    });
                }
            });
            
            // Reset plugin
            $('#reset-plugin').on('click', function() {
                var confirm1 = confirm('<?php echo esc_js(rb_t('reset_confirm_1')); ?>');
                if (confirm1) {
                    var confirm2 = confirm('<?php echo esc_js(rb_t('reset_confirm_2')); ?>');
                    if (confirm2) {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'rb_reset_plugin',
                                nonce: '<?php echo wp_create_nonce("rb_admin_nonce"); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert('<?php echo esc_js(rb_t('plugin_reset_success')); ?>');
                                    location.reload();
                                }
                            }
                        });
                    }
                }
            });
        });
                jQuery(document).ready(function($) {
            // Tab switching (existing code remains)
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                var target = $(this).attr('href');

                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');

                $('.rb-tab-content').hide();
                $(target).show();
            });

            // ✅ NEW: Reset language
            $('#rb-reset-language').on('click', function() {
                if (confirm('<?php echo esc_js(rb_t('reset_language_confirm')); ?>')) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'rb_reset_language',
                            nonce: '<?php echo wp_create_nonce("rb_admin_nonce"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('<?php echo esc_js(rb_t('language_reset_success')); ?>');
                                location.reload();
                            }
                        }
                    });
                }
            });
        });
        </script>
        <?php
    }
    
    public function handle_admin_actions() {
        if (!isset($_GET['page']) || (strpos($_GET['page'], 'restaurant-booking') === false && strpos($_GET['page'], 'rb-') === false)) {
            return;
        }

        // Handle export CSV (GET request)
        if (isset($_GET['action']) && $_GET['action'] === 'export_csv' && isset($_GET['page']) && $_GET['page'] === 'rb-settings') {
            if (!current_user_can('manage_options')) {
                wp_die('Unauthorized');
            }
            $this->export_bookings_csv();
            exit;
        }

        if (!isset($_GET['_wpnonce']) && !isset($_POST['rb_nonce'])) {
            return;
        }

        if (isset($_GET['action']) && isset($_GET['_wpnonce'])) {
            if (!wp_verify_nonce($_GET['_wpnonce'], 'rb_action')) {
                wp_die(rb_t('security_check_failed'));
            }

            $action = sanitize_text_field($_GET['action']);
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

            switch ($action) {
                case 'confirm':
                    $this->confirm_booking($id);
                    break;
                case 'cancel':
                    $this->cancel_booking($id);
                    break;
                case 'complete':
                    $this->complete_booking($id);
                    break;
                case 'delete':
                    $this->delete_booking($id);
                    break;
                case 'delete_table':
                    $this->delete_table($id);
                    break;
                case 'delete_portal_account':
                    $this->delete_portal_account($id);
                    break;
            }
        }

        if (isset($_POST['action']) && isset($_POST['rb_nonce'])) {
            $action = sanitize_text_field($_POST['action']);

            switch ($action) {
                case 'save_settings':
                    if (wp_verify_nonce($_POST['rb_nonce'], 'rb_save_settings')) {
                        $this->save_settings();
                    }
                    break;
                case 'save_portal_account':
                    if (wp_verify_nonce($_POST['rb_nonce'], 'rb_save_portal_account')) {
                        $this->save_portal_account();
                    }
                    break;
                case 'add_table':
                    if (wp_verify_nonce($_POST['rb_nonce'], 'rb_add_table')) {
                        $this->add_table();
                    }
                    break;
                case 'create_admin_booking':
                    if (wp_verify_nonce($_POST['rb_nonce'], 'rb_create_admin_booking')) {
                        $this->create_admin_booking();
                    }
                    break;
            }
        }
    }
    
    private function create_admin_booking() {
        global $wpdb, $rb_booking, $rb_location;

        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $location = $rb_location ? $rb_location->get($location_id) : null;

        if (!$location_id || !$location) {
            $redirect_url = add_query_arg(
                array(
                    'page' => 'rb-create-booking',
                    'message' => 'invalid_location'
                ),
                admin_url('admin.php')
            );

            wp_redirect($redirect_url);
            exit;
        }

        $booking_data = array(
            'customer_name' => sanitize_text_field($_POST['customer_name']),
            'customer_phone' => sanitize_text_field($_POST['customer_phone']),
            'customer_email' => sanitize_email($_POST['customer_email']),
            'guest_count' => intval($_POST['guest_count']),
            'booking_date' => sanitize_text_field($_POST['booking_date']),
            'booking_time' => sanitize_text_field($_POST['booking_time']),
            'booking_source' => sanitize_text_field($_POST['booking_source']),
            'special_requests' => isset($_POST['special_requests']) ? sanitize_textarea_field($_POST['special_requests']) : '',
            'admin_notes' => isset($_POST['admin_notes']) ? sanitize_textarea_field($_POST['admin_notes']) : '',
            'status' => 'pending',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
            'location_id' => $location_id
        );

        $is_available = $rb_booking->is_time_slot_available(
            $booking_data['booking_date'],
            $booking_data['booking_time'],
            $booking_data['guest_count'],
            null,
            $location_id
        );

        if (!$is_available) {
            $redirect_url = add_query_arg(
                array(
                    'page' => 'rb-create-booking',
                    'message' => 'no_availability',
                    'location_id' => $location_id
                ),
                admin_url('admin.php')
            );

            wp_redirect($redirect_url);
            exit;
        }

        $booking_id = $rb_booking->create_booking($booking_data);

        if (is_wp_error($booking_id)) {
            $redirect_url = add_query_arg(
                array(
                    'page' => 'rb-create-booking',
                    'message' => 'error',
                    'location_id' => $location_id
                ),
                admin_url('admin.php')
            );

            wp_redirect($redirect_url);
            exit;
        }
        
        if (isset($_POST['auto_confirm']) && $_POST['auto_confirm'] == '1') {
            $result = $rb_booking->confirm_booking($booking_id);
            
            if (!is_wp_error($result)) {
                $booking = $rb_booking->get_booking($booking_id);
                if ($booking && class_exists('RB_Email')) {
                    $email = new RB_Email();
                    $email->send_confirmation_email($booking);
                }
            }
        }

        $redirect_url = add_query_arg(
            array(
                'page' => 'restaurant-booking',
                'message' => 'admin_booking_created',
                'location_id' => $location_id
            ),
            admin_url('admin.php')
        );

        wp_redirect($redirect_url);
        exit;
    }
    
    private function confirm_booking($id) {
        global $wpdb, $rb_booking;
        $table_name = $wpdb->prefix . 'rb_bookings';

        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));

        if (!$booking) {
            wp_redirect(admin_url('admin.php?page=restaurant-booking&message=booking_not_found'));
            exit;
        }

        $result = $rb_booking->confirm_booking($id);

        if (is_wp_error($result)) {
            $error_message = urlencode($result->get_error_message());
            wp_redirect(admin_url('admin.php?page=restaurant-booking&message=no_tables&error=' . $error_message));
            exit;
        }

        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));
        if ($booking && class_exists('RB_Email')) {
            $email = new RB_Email();
            $email->send_confirmation_email($booking);
        }

        wp_redirect(admin_url('admin.php?page=restaurant-booking&message=confirmed'));
        exit;
    }
    
    private function cancel_booking($id) {
        global $rb_booking;
        $rb_booking->cancel_booking($id);
        
        wp_redirect(admin_url('admin.php?page=restaurant-booking&message=cancelled'));
        exit;
    }
    
    private function complete_booking($id) {
        global $rb_booking;
        $rb_booking->complete_booking($id);
        
        wp_redirect(admin_url('admin.php?page=restaurant-booking&message=completed'));
        exit;
    }
    
    private function delete_booking($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rb_bookings';
        
        $wpdb->delete($table_name, array('id' => $id));
        
        wp_redirect(admin_url('admin.php?page=restaurant-booking&message=deleted'));
        exit;
    }
    
    private function delete_table($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rb_tables';

        $location_id = $wpdb->get_var($wpdb->prepare("SELECT location_id FROM $table_name WHERE id = %d", $id));

        $wpdb->delete($table_name, array('id' => $id));

        $redirect_args = array(
            'page' => 'rb-tables',
            'message' => 'deleted'
        );

        if ($location_id) {
            $redirect_args['location_id'] = (int) $location_id;
        }

        wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }
    
    private function add_table() {
        global $wpdb, $rb_location;
        $table_name = $wpdb->prefix . 'rb_tables';

        $table_number = intval($_POST['table_number']);
        $capacity = intval($_POST['capacity']);
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if (!$rb_location) {
            require_once RB_PLUGIN_DIR . 'includes/class-location.php';
            $rb_location = new RB_Location();
        }

        $location = $rb_location ? $rb_location->get($location_id) : null;

        if (!$location_id || !$location) {
            $redirect_url = add_query_arg(
                array(
                    'page' => 'rb-tables',
                    'message' => 'invalid_location'
                ),
                admin_url('admin.php')
            );

            wp_redirect($redirect_url);
            exit;
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE table_number = %d AND location_id = %d",
            $table_number,
            $location_id
        ));

        if ($exists) {
            $redirect_url = add_query_arg(
                array(
                    'page' => 'rb-tables',
                    'message' => 'exists',
                    'location_id' => $location_id
                ),
                admin_url('admin.php')
            );

            wp_redirect($redirect_url);
            exit;
        }

        $wpdb->insert(
            $table_name,
            array(
                'location_id' => $location_id,
                'table_number' => $table_number,
                'capacity' => $capacity,
                'is_available' => 1,
                'created_at' => current_time('mysql')
            )
        );

        $redirect_url = add_query_arg(
            array(
                'page' => 'rb-tables',
                'message' => 'added',
                'location_id' => $location_id
            ),
            admin_url('admin.php')
        );

        wp_redirect($redirect_url);
        exit;
    }
    
    private function save_settings() {
        $settings = isset($_POST['rb_settings']) ? $_POST['rb_settings'] : array();
        
        $clean_settings = array(
            // Working hours
            'working_hours_mode' => isset($settings['working_hours_mode']) ? sanitize_text_field($settings['working_hours_mode']) : 'simple',
            'opening_time' => isset($settings['opening_time']) ? sanitize_text_field($settings['opening_time']) : '09:00',
            'closing_time' => isset($settings['closing_time']) ? sanitize_text_field($settings['closing_time']) : '22:00',
            'lunch_break_enabled' => isset($settings['lunch_break_enabled']) ? 'yes' : 'no',
            'lunch_break_start' => isset($settings['lunch_break_start']) ? sanitize_text_field($settings['lunch_break_start']) : '14:00',
            'lunch_break_end' => isset($settings['lunch_break_end']) ? sanitize_text_field($settings['lunch_break_end']) : '17:00',
            'morning_shift_start' => isset($settings['morning_shift_start']) ? sanitize_text_field($settings['morning_shift_start']) : '09:00',
            'morning_shift_end' => isset($settings['morning_shift_end']) ? sanitize_text_field($settings['morning_shift_end']) : '14:00',
            'evening_shift_start' => isset($settings['evening_shift_start']) ? sanitize_text_field($settings['evening_shift_start']) : '17:00',
            'evening_shift_end' => isset($settings['evening_shift_end']) ? sanitize_text_field($settings['evening_shift_end']) : '22:00',
            'weekend_enabled' => isset($settings['weekend_enabled']) ? 'yes' : 'no',
            
            // Booking settings
            'time_slot_interval' => isset($settings['time_slot_interval']) ? intval($settings['time_slot_interval']) : 30,
            'booking_buffer_time' => isset($settings['booking_buffer_time']) ? intval($settings['booking_buffer_time']) : 0,
            'min_advance_booking' => isset($settings['min_advance_booking']) ? intval($settings['min_advance_booking']) : 2,
            'max_advance_booking' => isset($settings['max_advance_booking']) ? intval($settings['max_advance_booking']) : 30,
            'max_guests_per_booking' => isset($settings['max_guests_per_booking']) ? intval($settings['max_guests_per_booking']) : 20,
            'auto_confirm_enabled' => isset($settings['auto_confirm_enabled']) ? 'yes' : 'no',
            
            // Notifications
            'admin_email' => isset($settings['admin_email']) ? sanitize_email($settings['admin_email']) : get_option('admin_email'),
            'enable_email' => isset($settings['enable_email']) ? 'yes' : 'no',
            'enable_sms' => isset($settings['enable_sms']) ? 'yes' : 'no',
            'sms_api_key' => isset($settings['sms_api_key']) ? sanitize_text_field($settings['sms_api_key']) : '',
            'reminder_hours_before' => isset($settings['reminder_hours_before']) ? intval($settings['reminder_hours_before']) : 24,
            
            // Policies
            'require_deposit' => isset($settings['require_deposit']) ? 'yes' : 'no',
            'deposit_amount' => isset($settings['deposit_amount']) ? intval($settings['deposit_amount']) : 100000,
            'deposit_for_guests' => isset($settings['deposit_for_guests']) ? intval($settings['deposit_for_guests']) : 10,
            'cancellation_hours' => isset($settings['cancellation_hours']) ? intval($settings['cancellation_hours']) : 2,
            'no_show_auto_blacklist' => isset($settings['no_show_auto_blacklist']) ? intval($settings['no_show_auto_blacklist']) : 3,
            'special_closed_dates' => isset($settings['special_closed_dates']) ? sanitize_textarea_field($settings['special_closed_dates']) : '',
        );
        
        update_option('rb_settings', $clean_settings);
        
        wp_redirect(admin_url('admin.php?page=rb-settings&message=saved'));
        exit;
    }
    
    public function display_admin_notices() {
        if (!isset($_GET['message'])) {
            return;
        }

        $message = sanitize_text_field($_GET['message']);
        $text = '';
        $type = 'success';

        switch ($message) {
            case 'admin_booking_created':
                $text = rb_t('booking_created_successfully');
                break;
            case 'no_availability':
                $text = rb_t('no_tables_available_time');
                $type = 'error';
                break;
            case 'confirmed':
                $text = rb_t('booking_confirmed');
                break;
            case 'cancelled':
                $text = rb_t('booking_cancelled');
                break;
            case 'completed':
                $text = rb_t('booking_completed');
                break;
            case 'deleted':
                $text = rb_t('deleted_successfully');
                break;
            case 'saved':
                $text = '✅ ' . rb_t('settings_saved_successfully');
                break;
            case 'added':
                $text = rb_t('table_added');
                break;
            case 'exists':
                $text = rb_t('table_number_exists');
                $type = 'error';
                break;
            case 'error':
                $text = __('Something went wrong while processing your request. Please try again.', 'restaurant-booking');
                $type = 'error';
                break;
            case 'invalid_location':
                $text = __('Please choose a valid location before continuing.', 'restaurant-booking');
                $type = 'error';
                break;
            case 'booking_not_found':
                $text = rb_t('booking_not_found');
                $type = 'error';
                break;
            case 'no_tables':
                $error_detail = isset($_GET['error']) ? urldecode($_GET['error']) : rb_t('no_tables_available');
                $text = rb_t('cannot_confirm') . ': ' . $error_detail;
                $type = 'error';
                break;
            case 'portal_account_saved':
                $text = __('Portal account saved successfully.', 'restaurant-booking');
                break;
            case 'portal_account_deleted':
                $text = __('Portal account deleted.', 'restaurant-booking');
                break;
            case 'portal_account_error':
                $text = isset($_GET['error']) ? esc_html(urldecode($_GET['error'])) : __('Unable to process the portal account request.', 'restaurant-booking');
                $type = 'error';
                break;
        }

        if ($text) {
            ?>
            <div class="notice notice-<?php echo $type; ?> is-dismissible">
                <p><?php echo $text; ?></p>
            </div>
            <?php
        }
    }
        
    private function get_status_label($status) {
        $labels = array(
            'pending' => rb_t('pending'),
            'confirmed' => rb_t('confirmed'),
            'cancelled' => rb_t('cancelled'),
            'completed' => rb_t('completed')
        );
        
        return isset($labels[$status]) ? $labels[$status] : $status;
    }
    
    private function get_source_label($source) {
        $labels = array(
            'website' => '🌐 ' . rb_t('website'),
            'phone' => '📞 ' . rb_t('phone'),
            'facebook' => '📘 ' . rb_t('facebook'),
            'zalo' => '💬 ' . rb_t('zalo'),
            'instagram' => '📷 ' . rb_t('instagram'),
            'walk-in' => '🚶 ' . rb_t('walk_in'),
            'email' => '✉️ ' . rb_t('email'),
            'other' => '❓ ' . rb_t('other')
        );
        
        return isset($labels[$source]) ? $labels[$source] : $source;
    }
    
    /**
     * Generate time slots với hỗ trợ giờ nghỉ trưa & 2 ca
     */
    private function generate_time_slots($start = null, $end = null, $interval = null) {
        $settings = get_option('rb_settings', array());
        
        $mode = isset($settings['working_hours_mode']) ? $settings['working_hours_mode'] : 'simple';
        $interval = $interval ?: (isset($settings['time_slot_interval']) ? intval($settings['time_slot_interval']) : 30);
        $buffer = isset($settings['booking_buffer_time']) ? intval($settings['booking_buffer_time']) : 0;
        
        $slots = array();
        
        if ($mode === 'advanced') {
            // Advanced mode: 2 shifts
            $morning_start = isset($settings['morning_shift_start']) ? $settings['morning_shift_start'] : '09:00';
            $morning_end = isset($settings['morning_shift_end']) ? $settings['morning_shift_end'] : '14:00';
            $evening_start = isset($settings['evening_shift_start']) ? $settings['evening_shift_start'] : '17:00';
            $evening_end = isset($settings['evening_shift_end']) ? $settings['evening_shift_end'] : '22:00';
            
            // Morning shift
            $slots = array_merge($slots, $this->generate_shift_slots($morning_start, $morning_end, $interval, $buffer));
            
            // Evening shift
            $slots = array_merge($slots, $this->generate_shift_slots($evening_start, $evening_end, $interval, $buffer));
            
        } else {
            // Simple mode
            $start = $start ?: (isset($settings['opening_time']) ? $settings['opening_time'] : '09:00');
            $end = $end ?: (isset($settings['closing_time']) ? $settings['closing_time'] : '22:00');
            
            $has_lunch_break = isset($settings['lunch_break_enabled']) && $settings['lunch_break_enabled'] === 'yes';
            
            if ($has_lunch_break) {
                $lunch_start = isset($settings['lunch_break_start']) ? $settings['lunch_break_start'] : '14:00';
                $lunch_end = isset($settings['lunch_break_end']) ? $settings['lunch_break_end'] : '17:00';
                
                // Before lunch
                $slots = array_merge($slots, $this->generate_shift_slots($start, $lunch_start, $interval, $buffer));
                
                // After lunch
                $slots = array_merge($slots, $this->generate_shift_slots($lunch_end, $end, $interval, $buffer));
            } else {
                // No lunch break
                $slots = $this->generate_shift_slots($start, $end, $interval, $buffer);
            }
        }
        
        return $slots;
    }

    /**
     * Generate slots cho 1 ca cụ thể
     */
    private function generate_shift_slots($start, $end, $interval, $buffer = 0) {
        $slots = array();
        $start_time = strtotime($start);
        $end_time = strtotime($end);
        $step = ($interval + $buffer) * 60; // Convert to seconds
        
        while ($start_time < $end_time) {
            $slots[] = date('H:i', $start_time);
            $start_time += $step;
        }
        
        return $slots;
    }

    /**
     * Kiểm tra xem 1 ngày có phải ngày nghỉ đặc biệt không
     */
    public function is_special_closed_date($date) {
        $settings = get_option('rb_settings', array());
        $closed_dates = isset($settings['special_closed_dates']) ? $settings['special_closed_dates'] : '';
        
        if (empty($closed_dates)) {
            return false;
        }
        
        $dates_array = explode("\n", $closed_dates);
        $dates_array = array_map('trim', $dates_array);
        
        return in_array($date, $dates_array);
    }

    /**
     * Kiểm tra xem có thể booking vào ngày này không
     */
    public function is_booking_allowed_on_date($date) {
        $settings = get_option('rb_settings', array());
        
        // Check special closed dates
        if ($this->is_special_closed_date($date)) {
            return false;
        }
        
        // Check weekend
        $weekend_enabled = isset($settings['weekend_enabled']) && $settings['weekend_enabled'] === 'yes';
        $day_of_week = date('N', strtotime($date)); // 1 (Monday) to 7 (Sunday)
        
        if (!$weekend_enabled && ($day_of_week == 6 || $day_of_week == 7)) {
            return false;
        }
        
        // Check advance booking limits
        $min_advance = isset($settings['min_advance_booking']) ? intval($settings['min_advance_booking']) : 2;
        $max_advance = isset($settings['max_advance_booking']) ? intval($settings['max_advance_booking']) : 30;
        
        $booking_datetime = strtotime($date);
        $now = current_time('timestamp');
        $min_datetime = $now + ($min_advance * 3600); // hours to seconds
        $max_datetime = $now + ($max_advance * 86400); // days to seconds
        
        if ($booking_datetime < $min_datetime || $booking_datetime > $max_datetime) {
            return false;
        }
        
        return true;
    }

    /**
     * Lấy thông tin giờ làm việc để hiển thị cho frontend
     */
    public function get_working_hours_info() {
        $settings = get_option('rb_settings', array());
        $mode = isset($settings['working_hours_mode']) ? $settings['working_hours_mode'] : 'simple';
        
        $info = array(
            'mode' => $mode,
            'time_slots' => $this->generate_time_slots()
        );
        
        if ($mode === 'advanced') {
            $info['morning_shift'] = array(
                'start' => isset($settings['morning_shift_start']) ? $settings['morning_shift_start'] : '09:00',
                'end' => isset($settings['morning_shift_end']) ? $settings['morning_shift_end'] : '14:00'
            );
            $info['evening_shift'] = array(
                'start' => isset($settings['evening_shift_start']) ? $settings['evening_shift_start'] : '17:00',
                'end' => isset($settings['evening_shift_end']) ? $settings['evening_shift_end'] : '22:00'
            );
        } else {
            $info['opening_time'] = isset($settings['opening_time']) ? $settings['opening_time'] : '09:00';
            $info['closing_time'] = isset($settings['closing_time']) ? $settings['closing_time'] : '22:00';
            
            if (isset($settings['lunch_break_enabled']) && $settings['lunch_break_enabled'] === 'yes') {
                $info['lunch_break'] = array(
                    'start' => isset($settings['lunch_break_start']) ? $settings['lunch_break_start'] : '14:00',
                    'end' => isset($settings['lunch_break_end']) ? $settings['lunch_break_end'] : '17:00'
                );
            }
        }
        
        return $info;
    }
    
    /**
     * Export bookings to CSV
     */
    private function export_bookings_csv() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rb_bookings';
        
        $bookings = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
        
        // Set headers for download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=restaurant-bookings-' . date('Y-m-d') . '.csv');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Headers
        fputcsv($output, array(
            'ID',
            rb_t('customer_name'),
            rb_t('phone'),
            rb_t('email'),
            rb_t('booking_date'),
            rb_t('booking_time'),
            rb_t('guests'),
            rb_t('table_number'),
            rb_t('source'),
            rb_t('status'),
            rb_t('special_requests'),
            rb_t('admin_notes'),
            rb_t('created_at')
        ));
        
        // Data rows
        foreach ($bookings as $booking) {
            fputcsv($output, array(
                $booking->id,
                $booking->customer_name,
                $booking->customer_phone,
                $booking->customer_email,
                $booking->booking_date,
                $booking->booking_time,
                $booking->guest_count,
                $booking->table_number ?: '-',
                $booking->booking_source ?: 'website',
                $booking->status,
                $booking->special_requests ?: '',
                isset($booking->admin_notes) ? $booking->admin_notes : '',
                $booking->created_at
            ));
        }
        
        fclose($output);
        exit;
    }
}