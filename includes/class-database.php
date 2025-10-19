<?php
/**
 * Database Class - Tạo và quản lý database tables
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Database {

    private $wpdb;
    private $charset_collate;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->charset_collate = $wpdb->get_charset_collate();
    }

    public function create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $this->create_locations_table();
        $this->create_tables_table();
        $this->create_bookings_table();
        $this->create_customers_table();
        $this->create_portal_accounts_table();
        $this->create_portal_account_locations_table();

        $this->add_location_columns();
        $this->insert_default_locations();
        $this->insert_default_tables();
        $this->add_booking_source_column();
    }

    public function ensure_portal_schema() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $accounts_table = $this->wpdb->prefix . 'rb_portal_accounts';
        $locations_table = $this->wpdb->prefix . 'rb_portal_account_locations';

        if ($this->table_exists($accounts_table)) {
            $this->maybe_add_column($accounts_table, 'password_hash', "ALTER TABLE {$accounts_table} ADD COLUMN password_hash varchar(255) NOT NULL AFTER username");
            $this->maybe_add_column($accounts_table, 'display_name', "ALTER TABLE {$accounts_table} ADD COLUMN display_name varchar(100) DEFAULT '' AFTER password_hash");
            $this->maybe_add_column($accounts_table, 'email', "ALTER TABLE {$accounts_table} ADD COLUMN email varchar(100) DEFAULT NULL AFTER display_name");
            $this->maybe_add_column($accounts_table, 'status', "ALTER TABLE {$accounts_table} ADD COLUMN status varchar(20) NOT NULL DEFAULT 'active' AFTER email");
            $this->maybe_add_column($accounts_table, 'last_location_id', "ALTER TABLE {$accounts_table} ADD COLUMN last_location_id bigint(20) UNSIGNED DEFAULT 0 AFTER status");
            $this->maybe_add_column($accounts_table, 'last_login_at', "ALTER TABLE {$accounts_table} ADD COLUMN last_login_at datetime DEFAULT NULL AFTER last_location_id");
            $this->maybe_add_column($accounts_table, 'created_at', "ALTER TABLE {$accounts_table} ADD COLUMN created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER last_login_at");
            $this->maybe_add_column($accounts_table, 'updated_at', "ALTER TABLE {$accounts_table} ADD COLUMN updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");

            $this->maybe_add_index($accounts_table, 'username', "ALTER TABLE {$accounts_table} ADD UNIQUE KEY username (username)");
            $this->maybe_add_index($accounts_table, 'email', "ALTER TABLE {$accounts_table} ADD UNIQUE KEY email (email)");
        } else {
            $this->create_portal_accounts_table();
        }

        if ($this->table_exists($locations_table)) {
            $this->maybe_add_index($locations_table, 'location_id', "ALTER TABLE {$locations_table} ADD KEY location_id (location_id)");
        } else {
            $this->create_portal_account_locations_table();
        }
    }

    private function create_bookings_table() {
        $table_name = $this->wpdb->prefix . 'rb_bookings';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            location_id int(11) NOT NULL DEFAULT 1,
            customer_name varchar(100) NOT NULL,
            customer_phone varchar(20) NOT NULL,
            customer_email varchar(100) NOT NULL,
            guest_count int(11) NOT NULL,
            booking_date date NOT NULL,
            booking_time time NOT NULL,
            table_number int(11) DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            booking_source varchar(50) DEFAULT 'website',
            language varchar(10) DEFAULT 'vi',
            special_requests text DEFAULT NULL,
            admin_notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            confirmed_at datetime DEFAULT NULL,
            confirmation_token varchar(64) DEFAULT NULL,
            confirmation_token_expires datetime DEFAULT NULL,
            confirmed_via varchar(20) DEFAULT NULL,
            created_by int(11) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY location_date_time (location_id, booking_date, booking_time),
            KEY booking_date (booking_date),
            KEY status (status),
            KEY booking_source (booking_source)
        ) $this->charset_collate;";

        dbDelta($sql);
    }

    private function create_tables_table() {
        $table_name = $this->wpdb->prefix . 'rb_tables';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            location_id int(11) NOT NULL DEFAULT 1,
            table_number int(11) NOT NULL,
            capacity int(11) NOT NULL,
            is_available tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY location_table (location_id, table_number)
        ) $this->charset_collate;";

        dbDelta($sql);
    }

    private function create_customers_table() {
        $table_name = $this->wpdb->prefix . 'rb_customers';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            location_id int(11) NOT NULL DEFAULT 1,
            phone varchar(20) NOT NULL,
            email varchar(100),
            name varchar(100),
            total_bookings int DEFAULT 0,
            completed_bookings int DEFAULT 0,
            cancelled_bookings int DEFAULT 0,
            no_shows int DEFAULT 0,
            last_visit date,
            first_visit date,
            customer_notes text,
            vip_status tinyint(1) DEFAULT 0,
            blacklisted tinyint(1) DEFAULT 0,
            preferred_source varchar(50),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY location_phone (location_id, phone),
            KEY phone (phone),
            KEY email (email)
        ) $this->charset_collate;";

        dbDelta($sql);
    }

    private function create_locations_table() {
        $table_name = $this->wpdb->prefix . 'rb_locations';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            slug varchar(50) NOT NULL,
            name varchar(100) NOT NULL,
            email varchar(100) DEFAULT NULL,
            hotline varchar(30) DEFAULT NULL,
            address varchar(255) DEFAULT NULL,
            opening_time time DEFAULT '09:00:00',
            closing_time time DEFAULT '22:00:00',
            time_slot_interval int(11) DEFAULT 30,
            min_advance_booking int(11) DEFAULT 2,
            max_advance_booking int(11) DEFAULT 30,
            default_table_count int(11) DEFAULT 10,
            default_capacity int(11) DEFAULT 4,
            shift_notes text DEFAULT NULL,
            languages varchar(255) DEFAULT 'vi,en,ja',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $this->charset_collate;";

        dbDelta($sql);
    }

    private function create_portal_accounts_table() {
        $table_name = $this->wpdb->prefix . 'rb_portal_accounts';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            username varchar(60) NOT NULL,
            password_hash varchar(255) NOT NULL,
            display_name varchar(100) DEFAULT '',
            email varchar(100) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            last_location_id bigint(20) UNSIGNED DEFAULT 0,
            last_login_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY username (username),
            UNIQUE KEY email (email)
        ) $this->charset_collate;";

        dbDelta($sql);
    }

    private function create_portal_account_locations_table() {
        $table_name = $this->wpdb->prefix . 'rb_portal_account_locations';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            account_id bigint(20) UNSIGNED NOT NULL,
            location_id bigint(20) UNSIGNED NOT NULL,
            PRIMARY KEY (account_id, location_id),
            KEY location_id (location_id)
        ) $this->charset_collate;";

        dbDelta($sql);
    }

    private function table_exists($table) {
        $found = $this->wpdb->get_var(
            $this->wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        return $found === $table;
    }

    private function add_location_columns() {
        $bookings = $this->wpdb->prefix . 'rb_bookings';
        $tables = $this->wpdb->prefix . 'rb_tables';
        $customers = $this->wpdb->prefix . 'rb_customers';

        $this->maybe_add_column($bookings, 'location_id', "ALTER TABLE {$bookings} ADD COLUMN location_id int(11) NOT NULL DEFAULT 1 AFTER id");
        $this->maybe_add_column($bookings, 'language', "ALTER TABLE {$bookings} ADD COLUMN language varchar(10) DEFAULT 'vi' AFTER booking_source");
        $this->maybe_add_column($bookings, 'confirmation_token', "ALTER TABLE {$bookings} ADD COLUMN confirmation_token varchar(64) DEFAULT NULL AFTER confirmed_at");
        $this->maybe_add_column($bookings, 'confirmation_token_expires', "ALTER TABLE {$bookings} ADD COLUMN confirmation_token_expires datetime DEFAULT NULL AFTER confirmation_token");
        $this->maybe_add_column($bookings, 'confirmed_via', "ALTER TABLE {$bookings} ADD COLUMN confirmed_via varchar(20) DEFAULT NULL AFTER confirmation_token_expires");
        $this->maybe_add_index($bookings, 'location_date_time', "ALTER TABLE {$bookings} ADD KEY location_date_time (location_id, booking_date, booking_time)");

        $this->maybe_add_column($tables, 'location_id', "ALTER TABLE {$tables} ADD COLUMN location_id int(11) NOT NULL DEFAULT 1 AFTER id");
        $this->maybe_drop_index($tables, 'table_number');
        $this->maybe_add_index($tables, 'location_table', "ALTER TABLE {$tables} ADD UNIQUE KEY location_table (location_id, table_number)");

        $this->maybe_add_column($customers, 'location_id', "ALTER TABLE {$customers} ADD COLUMN location_id int(11) NOT NULL DEFAULT 1 AFTER id");
        $this->maybe_drop_index($customers, 'phone');
        $this->maybe_add_index($customers, 'location_phone', "ALTER TABLE {$customers} ADD UNIQUE KEY location_phone (location_id, phone)");
    }

    private function maybe_add_column($table, $column, $sql) {
        $column_exists = $this->wpdb->get_results(
            $this->wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column)
        );

        if (empty($column_exists)) {
            $this->wpdb->query($sql);
        }
    }

    private function maybe_add_index($table, $index, $sql) {
        $index_exists = $this->wpdb->get_results(
            $this->wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index)
        );

        if (empty($index_exists)) {
            $this->wpdb->query($sql);
        }
    }

    private function maybe_drop_index($table, $index) {
        $index_exists = $this->wpdb->get_results(
            $this->wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index)
        );

        if (!empty($index_exists)) {
            $this->wpdb->query("ALTER TABLE {$table} DROP INDEX {$index}");
        }
    }

    public function add_booking_source_column() {
        $table_name = $this->wpdb->prefix . 'rb_bookings';

        $this->maybe_add_column($table_name, 'booking_source', "ALTER TABLE {$table_name} ADD COLUMN booking_source varchar(50) DEFAULT 'website' AFTER status");
        $this->maybe_add_column($table_name, 'admin_notes', "ALTER TABLE {$table_name} ADD COLUMN admin_notes text DEFAULT NULL AFTER special_requests");
        $this->maybe_add_column($table_name, 'created_by', "ALTER TABLE {$table_name} ADD COLUMN created_by int(11) DEFAULT NULL AFTER confirmed_at");
    }

    private function insert_default_locations() {
        $table = $this->wpdb->prefix . 'rb_locations';
        $count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        if ((int) $count === 0) {
            $defaults = array(
                array(
                    'slug' => 'hcm',
                    'name' => 'Ho Chi Minh',
                    'email' => get_option('admin_email'),
                    'hotline' => '+84 28 1234 5678',
                    'address' => 'Ho Chi Minh City, Vietnam',
                    'opening_time' => '09:00:00',
                    'closing_time' => '22:00:00',
                    'time_slot_interval' => 30,
                    'min_advance_booking' => 2,
                    'max_advance_booking' => 30,
                    'default_table_count' => 12,
                    'default_capacity' => 4,
                    'shift_notes' => __('Default shift configuration', 'restaurant-booking')
                ),
                array(
                    'slug' => 'hn',
                    'name' => 'Ha Noi',
                    'email' => get_option('admin_email'),
                    'hotline' => '+84 24 1234 5678',
                    'address' => 'Ha Noi, Vietnam',
                    'opening_time' => '09:00:00',
                    'closing_time' => '22:00:00',
                    'time_slot_interval' => 30,
                    'min_advance_booking' => 2,
                    'max_advance_booking' => 30,
                    'default_table_count' => 10,
                    'default_capacity' => 4,
                    'shift_notes' => __('Default shift configuration', 'restaurant-booking')
                ),
                array(
                    'slug' => 'jp',
                    'name' => 'Japan',
                    'email' => get_option('admin_email'),
                    'hotline' => '+81 3 1234 5678',
                    'address' => 'Tokyo, Japan',
                    'opening_time' => '10:00:00',
                    'closing_time' => '23:00:00',
                    'time_slot_interval' => 30,
                    'min_advance_booking' => 2,
                    'max_advance_booking' => 30,
                    'default_table_count' => 14,
                    'default_capacity' => 4,
                    'shift_notes' => __('Default shift configuration', 'restaurant-booking')
                ),
            );

            foreach ($defaults as $location) {
                $this->wpdb->insert(
                    $table,
                    $location
                );
            }
        }
    }

    private function insert_default_tables() {
        $table_name = $this->wpdb->prefix . 'rb_tables';
        $locations = $this->wpdb->prefix . 'rb_locations';

        $location_ids = $this->wpdb->get_col("SELECT id FROM {$locations}");

        foreach ($location_ids as $location_id) {
            $count = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE location_id = %d",
                $location_id
            ));

            if ((int) $count === 0) {
                $settings = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT default_table_count, default_capacity FROM {$locations} WHERE id = %d",
                    $location_id
                ));

                $tables = isset($settings->default_table_count) ? (int) $settings->default_table_count : 10;

                for ($i = 1; $i <= $tables; $i++) {
                    $capacity = ($i <= 4) ? 2 : (($i <= 8) ? 4 : 6);
                    if (!empty($settings->default_capacity)) {
                        $capacity = (int) $settings->default_capacity;
                    }

                    $this->wpdb->insert(
                        $table_name,
                        array(
                            'location_id' => (int) $location_id,
                            'table_number' => $i,
                            'capacity' => $capacity,
                            'is_available' => 1,
                            'created_at' => current_time('mysql')
                        ),
                        array('%d', '%d', '%d', '%d', '%s')
                    );
                }
            }
        }
    }

    public function drop_tables() {
        $tables = array(
            $this->wpdb->prefix . 'rb_bookings',
            $this->wpdb->prefix . 'rb_tables',
            $this->wpdb->prefix . 'rb_customers',
            $this->wpdb->prefix . 'rb_locations',
        );

        foreach ($tables as $table) {
            $this->wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }
}
