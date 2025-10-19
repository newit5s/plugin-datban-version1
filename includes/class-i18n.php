<?php
/**
 * Internationalization Class - Full Backend + Frontend Support
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_I18n {
    
    private $current_language;
    private $translations = array();
    private $default_language = 'vi_VN';
    private static $instance = null;
    private $session_supported = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->detect_language();
        $this->load_translations();
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // AJAX handler for language switching
        add_action('wp_ajax_rb_switch_language', array($this, 'ajax_switch_language'));
        add_action('wp_ajax_nopriv_rb_switch_language', array($this, 'ajax_switch_language'));
        
        // Add language to body class (frontend)
        add_filter('body_class', array($this, 'add_language_body_class'));
        
        // Add language to admin body class (backend)
        add_filter('admin_body_class', array($this, 'add_language_admin_class'));
        
        // ✅ NEW: Add admin action to force refresh language
        add_action('admin_init', array($this, 'handle_language_change'));
    }
    
    /**
     * ✅ NEW: Handle language change in admin
     */
    public function handle_language_change() {
        if (isset($_GET['rb_lang']) && is_admin()) {
            $lang = sanitize_text_field($_GET['rb_lang']);
            if ($this->is_valid_language($lang)) {
                $this->set_language($lang);
                
                // Redirect to remove rb_lang from URL
                $redirect_url = remove_query_arg('rb_lang');
                wp_safe_redirect($redirect_url);
                exit;
            }
        }
    }
    
    private function detect_language() {
        // Priority 1: Session (persists across pages)
        if ($this->can_use_sessions()) {
            if (isset($_SESSION['rb_language'])) {
                $session_lang = sanitize_text_field($_SESSION['rb_language']);
                if ($this->is_valid_language($session_lang)) {
                    $this->current_language = $session_lang;
                    return;
                }

                // Session contains an invalid language code – clean it up so the
                // rest of the detection chain can run without stale data.
                unset($_SESSION['rb_language']);
            }
        }

        // Priority 2: Cookie
        if (isset($_COOKIE['rb_language'])) {
            $cookie_lang = sanitize_text_field(wp_unslash($_COOKIE['rb_language']));

            if ($this->is_valid_language($cookie_lang)) {
                $this->current_language = $cookie_lang;
                if ($this->can_use_sessions()) {
                    $_SESSION['rb_language'] = $this->current_language;
                }
                return;
            }

            // Cookie value is invalid. Remove it to avoid language mismatch in the
            // following requests.
            setcookie('rb_language', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
            unset($_COOKIE['rb_language']);
        }
        
        // Priority 3: URL parameter
        if (isset($_GET['rb_lang'])) {
            $lang = sanitize_text_field($_GET['rb_lang']);
            if ($this->is_valid_language($lang)) {
                $this->set_language($lang);
                return;
            }
        }
        
        // Priority 4: User meta (for logged-in users)
        if (is_user_logged_in()) {
            $user_lang = get_user_meta(get_current_user_id(), 'rb_preferred_language', true);
            if ($user_lang && $this->is_valid_language($user_lang)) {
                $this->current_language = $user_lang;
                return;
            }
        }
        
        // Priority 5: WordPress locale
        $wp_locale = get_locale();
        if ($this->is_valid_language($wp_locale)) {
            $this->current_language = $wp_locale;
            return;
        }
        
        // Fallback: Default language
        $this->current_language = $this->default_language;
    }
    
    private function load_translations() {
        $file = RB_PLUGIN_DIR . 'languages/' . $this->current_language . '/translations.php';
        
        if (file_exists($file)) {
            $this->translations = include $file;
        } else {
            $default_file = RB_PLUGIN_DIR . 'languages/' . $this->default_language . '/translations.php';
            if (file_exists($default_file)) {
                $this->translations = include $default_file;
            }
        }
        
        $this->translations = apply_filters('rb_translations', $this->translations, $this->current_language);
    }
    
    public function translate($key, $default = '', $context = '') {
        if (!empty($context)) {
            $context_key = $context . '.' . $key;
            if (isset($this->translations[$context_key])) {
                return $this->translations[$context_key];
            }
        }
        
        if (isset($this->translations[$key])) {
            return $this->translations[$key];
        }
        
        return $default ?: $key;
    }
    
    public function __($key, $default = '', $context = '') {
        return $this->translate($key, $default, $context);
    }
    
    public function _e($key, $default = '', $context = '') {
        echo $this->translate($key, $default, $context);
    }
    
    public function get_available_languages() {
        return apply_filters('rb_available_languages', array(
            'vi_VN' => array(
                'name' => 'Tiếng Việt',
                'flag' => '🇻🇳',
                'code' => 'vi'
            ),
            'en_US' => array(
                'name' => 'English',
                'flag' => '🇺🇸',
                'code' => 'en'
            ),
            'ja_JP' => array(
                'name' => '日本語',
                'flag' => '🇯🇵',
                'code' => 'ja'
            )
        ));
    }
    
    private function is_valid_language($lang) {
        return array_key_exists($lang, $this->get_available_languages());
    }
    
    public function get_current_language() {
        return $this->current_language;
    }
    
    public function get_current_language_info() {
        $languages = $this->get_available_languages();
        return $languages[$this->current_language] ?? $languages[$this->default_language];
    }
    
    public function set_language($lang) {
        if ($this->is_valid_language($lang)) {
            $this->current_language = $lang;

            // Save to session
            if ($this->can_use_sessions()) {
                $_SESSION['rb_language'] = $lang;
            }

            // Save to cookie (30 days)
            setcookie('rb_language', $lang, time() + (86400 * 30), COOKIEPATH, COOKIE_DOMAIN, is_ssl());
            $_COOKIE['rb_language'] = $lang;
            
            // Save to user meta if logged in
            if (is_user_logged_in()) {
                update_user_meta(get_current_user_id(), 'rb_preferred_language', $lang);
            }
            
            // Reload translations
            $this->load_translations();
            
            return true;
        }
        return false;
    }
    
    /**
     * ✅ IMPROVED: AJAX handler for language switching
     */
    public function ajax_switch_language() {
        check_ajax_referer('rb_language_nonce', 'nonce');
        
        $lang = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';
        
        if ($this->set_language($lang)) {
            // ✅ Clear any cached data
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            
            wp_send_json_success(array(
                'message' => $this->translate('language_switched'),
                'language' => $lang,
                'redirect' => true
            ));
        } else {
            wp_send_json_error(array(
                'message' => $this->translate('invalid_language')
            ));
        }
    }
    
    public function add_language_body_class($classes) {
        $classes[] = 'rb-lang-' . $this->current_language;
        return $classes;
    }
    
    public function add_language_admin_class($classes) {
        return $classes . ' rb-lang-' . $this->current_language;
    }
    
    public function get_js_translations() {
        return array(
            'loading' => $this->translate('loading'),
            'error' => $this->translate('error'),
            'success' => $this->translate('success'),
            'confirm' => $this->translate('confirm'),
            'cancel' => $this->translate('cancel'),
            'delete_confirm' => $this->translate('delete_confirm'),
            'save' => $this->translate('save'),
            'saved' => $this->translate('saved'),
        );
    }
    
    /**
     * ✅ NEW: Reset language to default
     */
    public function reset_language() {
        if ($this->can_use_sessions() && isset($_SESSION['rb_language'])) {
            unset($_SESSION['rb_language']);
        }

        setcookie('rb_language', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl());

        if (is_user_logged_in()) {
            delete_user_meta(get_current_user_id(), 'rb_preferred_language');
        }
        
        $this->current_language = $this->default_language;
        $this->load_translations();
    }

    private function can_use_sessions() {
        if (true === $this->session_supported) {
            return true;
        }

        if (!function_exists('session_status') || !function_exists('session_start')) {
            return false;
        }

        $status = session_status();

        if (defined('PHP_SESSION_DISABLED') && PHP_SESSION_DISABLED === $status) {
            return false;
        }

        if (defined('PHP_SESSION_ACTIVE') && PHP_SESSION_ACTIVE === $status) {
            $this->session_supported = true;
            return true;
        }

        if (!defined('PHP_SESSION_NONE') || PHP_SESSION_NONE !== $status) {
            return false;
        }

        if (@session_start()) {
            $status = session_status();
            if (!defined('PHP_SESSION_ACTIVE') || PHP_SESSION_ACTIVE === $status) {
                $this->session_supported = true;
                return true;
            }
        }

        return false;
    }
}

// Global helper functions
function rb_t($key, $default = '', $context = '') {
    return RB_I18n::get_instance()->translate($key, $default, $context);
}

function rb_e($key, $default = '', $context = '') {
    echo rb_t($key, $default, $context);
}

function rb_get_current_language() {
    return RB_I18n::get_instance()->get_current_language();
}

function rb_get_available_languages() {
    return RB_I18n::get_instance()->get_available_languages();
}

/**
 * ✅ NEW: Helper to reset language
 */
function rb_reset_language() {
    return RB_I18n::get_instance()->reset_language();
}