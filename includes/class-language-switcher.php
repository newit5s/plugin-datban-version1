<?php
/**
 * Language Switcher Component
 * Works in both admin and frontend
 */

if (!defined('ABSPATH')) {
    exit;
}

class RB_Language_Switcher {
    
    private $i18n;
    
    public function __construct() {
        $this->i18n = RB_I18n::get_instance();
        $this->init_hooks();
    }
    
    private function init_hooks() {

        
        // Shortcode for frontend
        add_shortcode('rb_language_switcher', array($this, 'render_shortcode'));
        
        // Widget for frontend
        add_action('widgets_init', array($this, 'register_widget'));
    }
    
    /**
     * Add language switcher to admin bar
     */
    public function add_to_admin_bar($admin_bar) {
        $languages = $this->i18n->get_available_languages();
        $current = $this->i18n->get_current_language();
        $current_info = $languages[$current];
        
        // Parent menu
        $admin_bar->add_menu(array(
            'id'    => 'rb-language-switcher',
            'title' => $current_info['flag'] . ' ' . $current_info['name'],
            'href'  => '#',
            'meta'  => array(
                'title' => rb_t('switch_language'),
            ),
        ));
        
        // Language options
        foreach ($languages as $code => $info) {
            $admin_bar->add_menu(array(
                'parent' => 'rb-language-switcher',
                'id'     => 'rb-lang-' . $code,
                'title'  => $info['flag'] . ' ' . $info['name'],
                'href'   => add_query_arg('rb_lang', $code),
                'meta'   => array(
                    'class' => $current === $code ? 'rb-current-lang' : '',
                ),
            ));
        }
    }
    
    /**
     * Render dropdown (for admin pages and forms)
     */
    public function render_dropdown($echo = true) {
        $languages = $this->i18n->get_available_languages();
        $current = $this->i18n->get_current_language();
        
        ob_start();
        ?>
        <div class="rb-language-switcher-dropdown">
            <select id="rb-lang-select-<?php echo uniqid(); ?>" class="rb-lang-select" onchange="rbSwitchLanguage(this.value)">
                <?php foreach ($languages as $code => $info) : ?>
                    <option value="<?php echo esc_attr($code); ?>" <?php selected($current, $code); ?>>
                        <?php echo esc_html($info['flag'] . ' ' . $info['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <?php if (!wp_script_is('rb-lang-switcher-js', 'enqueued')) : ?>
        <script>
        window.rbSwitchLanguage = function(lang) {
            // Show loading
            var selects = document.querySelectorAll('.rb-lang-select');
            selects.forEach(function(select) {
                select.disabled = true;
                select.style.opacity = '0.5';
            });
            
            // Use AJAX for smoother experience
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onload = function() {
                // Reload page to apply new language
                window.location.reload();
            };
            
            xhr.onerror = function() {
                // Fallback: reload with URL parameter
                var currentUrl = window.location.href.split('?')[0];
                window.location.href = currentUrl + '?rb_lang=' + lang;
            };
            
            var params = 'action=rb_switch_language' +
                        '&language=' + encodeURIComponent(lang) +
                        '&nonce=<?php echo wp_create_nonce('rb_language_nonce'); ?>';
            
            xhr.send(params);
        };
        </script>
        <script type="text/javascript">
        // Mark script as loaded
        if (typeof wp !== 'undefined' && wp.hasOwnProperty('hooks')) {
            wp.hooks.addAction('rb-lang-switcher-loaded', 'rb', function() {});
        }
        </script>
        <?php endif; ?>
        
        <style>
        .rb-language-switcher-dropdown {
            display: inline-block;
        }
        .rb-lang-select {
            padding: 5px 10px;
            border: 1px solid #ddd;
            border-radius: 3px;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
        }
        .rb-lang-select:hover {
            border-color: #999;
        }
        .rb-lang-select:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        </style>
        <?php
        
        $output = ob_get_clean();
        
        if ($echo) {
            echo $output;
        }
        return $output;
    }
    
    /**
     * Render flags style (for frontend)
     */
    public function render_flags($echo = true) {
        $languages = $this->i18n->get_available_languages();
        $current = $this->i18n->get_current_language();
        
        ob_start();
        ?>
        <div class="rb-language-switcher-flags">
            <?php foreach ($languages as $code => $info) : ?>
                <a href="<?php echo add_query_arg('rb_lang', $code); ?>" 
                   class="rb-lang-flag <?php echo $current === $code ? 'active' : ''; ?>"
                   title="<?php echo esc_attr($info['name']); ?>"
                   data-lang="<?php echo esc_attr($code); ?>">
                    <span class="flag"><?php echo $info['flag']; ?></span>
                    <span class="name"><?php echo esc_html($info['name']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        
        <style>
        .rb-language-switcher-flags {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .rb-lang-flag {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border: 1px solid #ddd;
            border-radius: 3px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
        }
        .rb-lang-flag:hover {
            background: #f5f5f5;
            border-color: #999;
        }
        .rb-lang-flag.active {
            background: #2271b1;
            color: white;
            border-color: #2271b1;
        }
        .rb-lang-flag .flag {
            font-size: 20px;
        }
        .rb-lang-flag .name {
            font-size: 14px;
        }
        </style>
        <?php
        
        $output = ob_get_clean();
        
        if ($echo) {
            echo $output;
        }
        return $output;
    }
    
    /**
     * Shortcode renderer
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'style' => 'dropdown', // dropdown or flags
        ), $atts);
        
        if ($atts['style'] === 'flags') {
            return $this->render_flags(false);
        } else {
            return $this->render_dropdown(false);
        }
    }
    
    /**
     * Register widget
     */
    public function register_widget() {
        register_widget('RB_Language_Switcher_Widget');
    }
}

/**
 * Language Switcher Widget
 */
class RB_Language_Switcher_Widget extends WP_Widget {
    
    public function __construct() {
        parent::__construct(
            'rb_language_switcher_widget',
            rb_t('language_switcher'),
            array('description' => rb_t('language_switcher_description'))
        );
    }
    
    public function widget($args, $instance) {
        echo $args['before_widget'];
        
        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }
        
        $switcher = new RB_Language_Switcher();
        $style = !empty($instance['style']) ? $instance['style'] : 'dropdown';
        
        if ($style === 'flags') {
            $switcher->render_flags();
        } else {
            $switcher->render_dropdown();
        }
        
        echo $args['after_widget'];
    }
    
    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : rb_t('language');
        $style = !empty($instance['style']) ? $instance['style'] : 'dropdown';
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>"><?php rb_e('title'); ?>:</label>
            <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" 
                   name="<?php echo $this->get_field_name('title'); ?>" type="text" 
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('style'); ?>"><?php rb_e('style'); ?>:</label>
            <select class="widefat" id="<?php echo $this->get_field_id('style'); ?>" 
                    name="<?php echo $this->get_field_name('style'); ?>">
                <option value="dropdown" <?php selected($style, 'dropdown'); ?>><?php rb_e('dropdown'); ?></option>
                <option value="flags" <?php selected($style, 'flags'); ?>><?php rb_e('flags'); ?></option>
            </select>
        </p>
        <?php
    }
    
    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = (!empty($new_instance['title'])) ? strip_tags($new_instance['title']) : '';
        $instance['style'] = (!empty($new_instance['style'])) ? strip_tags($new_instance['style']) : 'dropdown';
        return $instance;
    }
}