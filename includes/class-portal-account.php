<?php
/**
 * Portal account management for the Restaurant Booking Manager plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Portal_Account_Manager {
    /** @var self */
    private static $instance;

    /** @var wpdb */
    private $wpdb;

    /** @var string */
    private $accounts_table;

    /** @var string */
    private $locations_table;

    private function __construct() {
        global $wpdb;

        $this->wpdb = $wpdb;
        $this->accounts_table = $wpdb->prefix . 'rb_portal_accounts';
        $this->locations_table = $wpdb->prefix . 'rb_portal_account_locations';
    }

    public static function get_instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function get_accounts() {
        $accounts = $this->wpdb->get_results(
            "SELECT * FROM {$this->accounts_table} ORDER BY username ASC"
        );

        if (empty($accounts)) {
            return array();
        }

        $location_map = $this->get_locations_for_accounts(wp_list_pluck($accounts, 'id'));

        foreach ($accounts as $account) {
            $account->locations = isset($location_map[$account->id]) ? $location_map[$account->id] : array();
        }

        return $accounts;
    }

    public function get_account($id) {
        $account = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->accounts_table} WHERE id = %d", $id)
        );

        if ($account) {
            $account->locations = $this->get_account_locations($account->id);
        }

        return $account;
    }

    public function get_account_by_identifier($identifier) {
        $identifier = trim($identifier);

        if (empty($identifier)) {
            return null;
        }

        if (strpos($identifier, '@') !== false) {
            $account = $this->wpdb->get_row(
                $this->wpdb->prepare("SELECT * FROM {$this->accounts_table} WHERE email = %s", $identifier)
            );
        } else {
            $account = $this->wpdb->get_row(
                $this->wpdb->prepare("SELECT * FROM {$this->accounts_table} WHERE username = %s", $identifier)
            );
        }

        if ($account) {
            $account->locations = $this->get_account_locations($account->id);
        }

        return $account;
    }

    public function username_exists($username, $exclude_id = 0) {
        $username = sanitize_user($username, true);

        if (empty($username)) {
            return false;
        }

        $sql = "SELECT id FROM {$this->accounts_table} WHERE username = %s";
        $params = array($username);

        if ($exclude_id) {
            $sql .= " AND id != %d";
            $params[] = $exclude_id;
        }

        return (bool) $this->wpdb->get_var($this->wpdb->prepare($sql, $params));
    }

    public function email_exists($email, $exclude_id = 0) {
        $email = sanitize_email($email);

        if (empty($email)) {
            return false;
        }

        $sql = "SELECT id FROM {$this->accounts_table} WHERE email = %s";
        $params = array($email);

        if ($exclude_id) {
            $sql .= " AND id != %d";
            $params[] = $exclude_id;
        }

        return (bool) $this->wpdb->get_var($this->wpdb->prepare($sql, $params));
    }

    public function create_account($data, $location_ids) {
        $username = isset($data['username']) ? sanitize_user($data['username'], true) : '';
        $email = isset($data['email']) ? sanitize_email($data['email']) : '';

        if (empty($username)) {
            return new WP_Error('rb_missing_username', __('Username is required.', 'restaurant-booking'));
        }

        if ($this->username_exists($username)) {
            return new WP_Error('rb_username_exists', __('Username already exists.', 'restaurant-booking'));
        }

        if (!empty($email) && $this->email_exists($email)) {
            return new WP_Error('rb_email_exists', __('Email already exists.', 'restaurant-booking'));
        }

        $password = isset($data['password']) ? (string) $data['password'] : '';
        if (empty($password)) {
            return new WP_Error('rb_missing_password', __('Password is required.', 'restaurant-booking'));
        }

        $inserted = $this->wpdb->insert(
            $this->accounts_table,
            array(
                'username' => $username,
                'password_hash' => wp_hash_password($password),
                'display_name' => isset($data['display_name']) ? sanitize_text_field($data['display_name']) : '',
                'email' => $email,
                'status' => isset($data['status']) && $data['status'] === 'inactive' ? 'inactive' : 'active',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'last_location_id' => isset($data['last_location_id']) ? intval($data['last_location_id']) : 0,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
        );

        if (false === $inserted) {
            $message = __('Unable to create portal account.', 'restaurant-booking');

            if (!empty($this->wpdb->last_error)) {
                /* translators: %s: database error message */
                $message .= ' ' . sprintf(__('Database error: %s', 'restaurant-booking'), $this->wpdb->last_error);
            }

            return new WP_Error('rb_db_error', $message);
        }

        $account_id = (int) $this->wpdb->insert_id;

        $this->set_account_locations($account_id, $location_ids);

        return $this->get_account($account_id);
    }

    public function update_account($account_id, $data, $location_ids) {
        $account = $this->get_account($account_id);

        if (!$account) {
            return new WP_Error('rb_account_missing', __('Portal account not found.', 'restaurant-booking'));
        }

        $username = isset($data['username']) ? sanitize_user($data['username'], true) : $account->username;
        $email = isset($data['email']) ? sanitize_email($data['email']) : $account->email;

        if (empty($username)) {
            return new WP_Error('rb_missing_username', __('Username is required.', 'restaurant-booking'));
        }

        if ($username !== $account->username && $this->username_exists($username, $account_id)) {
            return new WP_Error('rb_username_exists', __('Username already exists.', 'restaurant-booking'));
        }

        if (!empty($email) && $email !== $account->email && $this->email_exists($email, $account_id)) {
            return new WP_Error('rb_email_exists', __('Email already exists.', 'restaurant-booking'));
        }

        $fields = array(
            'username' => $username,
            'display_name' => isset($data['display_name']) ? sanitize_text_field($data['display_name']) : '',
            'email' => $email,
            'status' => isset($data['status']) && $data['status'] === 'inactive' ? 'inactive' : 'active',
            'updated_at' => current_time('mysql'),
        );
        $formats = array('%s', '%s', '%s', '%s', '%s');

        if (!empty($data['password'])) {
            $fields['password_hash'] = wp_hash_password($data['password']);
            $formats[] = '%s';
        }

        if (isset($data['last_location_id'])) {
            $fields['last_location_id'] = intval($data['last_location_id']);
            $formats[] = '%d';
        }

        $updated = $this->wpdb->update(
            $this->accounts_table,
            $fields,
            array('id' => $account_id),
            $formats,
            array('%d')
        );

        if (false === $updated) {
            $message = __('Unable to update portal account.', 'restaurant-booking');

            if (!empty($this->wpdb->last_error)) {
                /* translators: %s: database error message */
                $message .= ' ' . sprintf(__('Database error: %s', 'restaurant-booking'), $this->wpdb->last_error);
            }

            return new WP_Error('rb_db_error', $message);
        }

        $this->set_account_locations($account_id, $location_ids);

        return $this->get_account($account_id);
    }

    public function delete_account($account_id) {
        $this->wpdb->delete(
            $this->locations_table,
            array('account_id' => $account_id),
            array('%d')
        );

        $deleted = $this->wpdb->delete(
            $this->accounts_table,
            array('id' => $account_id),
            array('%d')
        );

        return false !== $deleted;
    }

    public function authenticate($identifier, $password) {
        $account = $this->get_account_by_identifier($identifier);

        if (!$account || empty($account->password_hash)) {
            return null;
        }

        if ($account->status !== 'active') {
            return new WP_Error('rb_account_inactive', __('This portal account is inactive.', 'restaurant-booking'));
        }

        if (!wp_check_password($password, $account->password_hash, null)) {
            return null;
        }

        $this->record_login($account->id);

        return $account;
    }

    public function record_login($account_id) {
        $this->wpdb->update(
            $this->accounts_table,
            array('last_login_at' => current_time('mysql')),
            array('id' => $account_id),
            array('%s'),
            array('%d')
        );
    }

    public function get_account_locations($account_id) {
        $results = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT location_id FROM {$this->locations_table} WHERE account_id = %d ORDER BY location_id ASC",
                $account_id
            )
        );

        return array_map('intval', $results);
    }

    public function set_account_locations($account_id, $location_ids) {
        $location_ids = array_map('intval', (array) $location_ids);
        $location_ids = array_values(array_unique(array_filter($location_ids)));

        $this->wpdb->delete(
            $this->locations_table,
            array('account_id' => $account_id),
            array('%d')
        );

        if (empty($location_ids)) {
            return;
        }

        $values = array();
        foreach ($location_ids as $location_id) {
            $values[] = $this->wpdb->prepare('(%d, %d)', $account_id, $location_id);
        }

        $sql = "INSERT INTO {$this->locations_table} (account_id, location_id) VALUES " . implode(', ', $values);
        $this->wpdb->query($sql);
    }

    public function set_active_location($account_id, $location_id) {
        $this->wpdb->update(
            $this->accounts_table,
            array(
                'last_location_id' => intval($location_id),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $account_id),
            array('%d', '%s'),
            array('%d')
        );
    }

    public function get_active_location($account_id) {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT last_location_id FROM {$this->accounts_table} WHERE id = %d", $account_id)
        );
    }

    private function get_locations_for_accounts($account_ids) {
        $account_ids = array_map('intval', (array) $account_ids);
        $account_ids = array_values(array_filter($account_ids));

        if (empty($account_ids)) {
            return array();
        }

        $placeholders = implode(', ', array_fill(0, count($account_ids), '%d'));

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT account_id, location_id FROM {$this->locations_table} WHERE account_id IN ({$placeholders})",
                $account_ids
            )
        );

        $map = array();
        foreach ($results as $row) {
            $account_id = (int) $row->account_id;
            if (!isset($map[$account_id])) {
                $map[$account_id] = array();
            }
            $map[$account_id][] = (int) $row->location_id;
        }

        return $map;
    }
}

class RB_Portal_Session_Manager {
    const COOKIE_NAME = 'rb_portal_session';
    const TRANSIENT_PREFIX = 'rb_portal_session_';
    const SESSION_TTL = 12 * HOUR_IN_SECONDS;

    /** @var RB_Portal_Account_Manager */
    private $account_manager;

    public function __construct() {
        $this->account_manager = RB_Portal_Account_Manager::get_instance();
    }

    public function start_session($account_id) {
        $token = wp_generate_password(32, false, false);
        $this->persist_token($token, $account_id);
        $this->set_cookie($token, time() + self::SESSION_TTL);

        return $token;
    }

    public function get_current_account() {
        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return null;
        }

        $token = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]));
        if (empty($token)) {
            return null;
        }

        $payload = get_transient($this->get_transient_key($token));
        if (!$payload || empty($payload['account_id'])) {
            return null;
        }

        $account = $this->account_manager->get_account((int) $payload['account_id']);

        if (!$account || $account->status !== 'active') {
            $this->destroy_session();
            return null;
        }

        // Extend the session on activity.
        $this->persist_token($token, (int) $payload['account_id']);

        return $account;
    }

    public function destroy_session() {
        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]));
        delete_transient($this->get_transient_key($token));

        $this->set_cookie('', time() - HOUR_IN_SECONDS);
    }

    private function persist_token($token, $account_id) {
        set_transient(
            $this->get_transient_key($token),
            array(
                'account_id' => (int) $account_id,
                'created_at' => time(),
            ),
            self::SESSION_TTL
        );
    }

    private function get_transient_key($token) {
        return self::TRANSIENT_PREFIX . $token;
    }

    private function set_cookie($value, $expires) {
        $path = defined('COOKIEPATH') ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $secure = is_ssl();
        $httponly = true;

        setcookie(self::COOKIE_NAME, $value, $expires, $path, $domain, $secure, $httponly);

        if (defined('SITECOOKIEPATH') && SITECOOKIEPATH !== $path) {
            setcookie(self::COOKIE_NAME, $value, $expires, SITECOOKIEPATH, $domain, $secure, $httponly);
        }

        if ($expires < time()) {
            unset($_COOKIE[self::COOKIE_NAME]);
        } else {
            $_COOKIE[self::COOKIE_NAME] = $value;
        }
    }
}
