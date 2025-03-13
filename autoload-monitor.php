<?php
/**
 * Plugin Name: OctaHexa Autoload Monitor
 * Description: Monitors and logs what is changing autoload values in wp_options
 * Version: 1.0.0
 * Author: Brian Chin
 * Author URI: https://octahexa.com
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main class to monitor autoload changes
 */
class OH_Autoload_Monitor {
    
    // Log file path
    private $log_file;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Set log file path in the uploads directory
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/autoload-monitor.log';
        
        // Hook into option operations
        add_action('added_option', array($this, 'log_added_option'), 10, 3);
        add_action('updated_option', array($this, 'log_updated_option'), 10, 3);
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    /**
     * Log when an option is added
     */
    public function log_added_option($option, $value, $autoload) {
        // Only log if autoload is not 'yes' or 'no'
        if ($autoload !== 'yes' && $autoload !== 'no') {
            $this->log_event('added', $option, $autoload);
        }
    }
    
    /**
     * Log when an option is updated
     */
    public function log_updated_option($option, $old_value, $value) {
        // We need to check the database since updated_option doesn't give us the autoload parameter
        global $wpdb;
        $autoload = $wpdb->get_var($wpdb->prepare(
            "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
            $option
        ));
        
        if ($autoload !== 'yes' && $autoload !== 'no') {
            $this->log_event('updated', $option, $autoload);
        }
    }
    
    /**
     * Log the event with caller information
     */
    private function log_event($action, $option, $autoload) {
        $caller = $this->get_caller_info();
        
        // Format the log entry
        $entry = sprintf(
            "[%s] %s option '%s' with autoload='%s' by %s\n",
            current_time('mysql'),
            $action,
            $option,
            $autoload,
            $caller
        );
        
        // Write to log file
        file_put_contents($this->log_file, $entry, FILE_APPEND);
    }
    
    /**
     * Get caller information from the stack trace
     */
    private function get_caller_info() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $caller = array();
        
        // Go back in the trace to find the original caller
        // Skip the first few entries which are this class's methods
        for ($i = 3; $i < count($trace); $i++) {
            if (isset($trace[$i]['class']) && $trace[$i]['class'] === __CLASS__) {
                continue;
            }
            
            // Add function/method
            if (isset($trace[$i]['class'])) {
                $caller[] = $trace[$i]['class'] . '::' . $trace[$i]['function'];
            } elseif (isset($trace[$i]['function'])) {
                $caller[] = $trace[$i]['function'];
            }
            
            // Add file and line
            if (isset($trace[$i]['file'])) {
                $file = str_replace(ABSPATH, '', $trace[$i]['file']);
                $caller[] = $file . ':' . $trace[$i]['line'];
                break;
            }
        }
        
        // If we have actual plugin info, try to get the plugin name
        $plugin_info = $this->get_plugin_from_trace($trace);
        if ($plugin_info) {
            $caller[] = '(Plugin: ' . $plugin_info . ')';
        }
        
        return implode(' via ', $caller);
    }
    
    /**
     * Try to determine which plugin is responsible
     */
    private function get_plugin_from_trace($trace) {
        $plugin_path = WP_PLUGIN_DIR;
        
        foreach ($trace as $item) {
            if (isset($item['file']) && strpos($item['file'], $plugin_path) === 0) {
                $plugin_file = str_replace($plugin_path . '/', '', $item['file']);
                $plugin_dir = explode('/', $plugin_file)[0];
                
                // Check if this is a plugin main file
                $plugins = get_plugins();
                foreach ($plugins as $path => $data) {
                    if (strpos($path, $plugin_dir . '/') === 0) {
                        return $data['Name'] . ' (' . $path . ')';
                    }
                }
                
                // Just return the directory if we can't find the plugin info
                return $plugin_dir;
            }
        }
        
        return false;
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Autoload Monitor',
            'Autoload Monitor',
            'manage_options',
            'autoload-monitor',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        global $wpdb;
        
        echo '<div class="wrap">';
        echo '<h1>Autoload Monitor</h1>';
        
        // Show autoload stats
        $results = $wpdb->get_results("SELECT autoload, COUNT(*) as count FROM {$wpdb->options} GROUP BY autoload");
        
        echo '<h2>Autoload Distribution</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Autoload Value</th><th>Count</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($results as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row->autoload) . '</td>';
            echo '<td>' . esc_html($row->count) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        // Show log file contents if it exists
        if (file_exists($this->log_file)) {
            echo '<h2>Recent Autoload Events</h2>';
            
            // Get the last 50 lines of the log
            $log_content = file_get_contents($this->log_file);
            $lines = explode("\n", $log_content);
            $lines = array_filter(array_slice($lines, -50));
            
            echo '<div style="background:#f5f5f5; padding:10px; border:1px solid #ccc; overflow:auto; max-height:400px;">';
            foreach ($lines as $line) {
                echo esc_html($line) . '<br>';
            }
            echo '</div>';
            
            // Add clear log button
            echo '<p><a href="' . esc_url(add_query_arg('action', 'clear_log')) . '" class="button">Clear Log</a></p>';
            
            // Handle clear log action
            if (isset($_GET['action']) && $_GET['action'] === 'clear_log' && current_user_can('manage_options')) {
                file_put_contents($this->log_file, '');
                echo '<div class="notice notice-success is-dismissible"><p>Log file cleared.</p></div>';
            }
        } else {
            echo '<p>No log file exists yet. It will be created when an option with a non-standard autoload value is detected.</p>';
        }
        
        // Fix autoload values
        echo '<h2>Fix Autoload Values</h2>';
        echo '<p>Click the button below to change non-standard autoload values to "yes":</p>';
        echo '<p><a href="' . esc_url(add_query_arg('action', 'fix_autoload')) . '" class="button button-primary" onclick="return confirm(\'This will update all options with non-standard autoload values to autoload=\\\'yes\\\'. Continue?\');">Fix Autoload Values</a></p>';
        
        // Handle fix action
        if (isset($_GET['action']) && $_GET['action'] === 'fix_autoload' && current_user_can('manage_options')) {
            $updated = $wpdb->query("UPDATE {$wpdb->options} SET autoload = 'yes' WHERE autoload NOT IN ('yes', 'no')");
            echo '<div class="notice notice-success is-dismissible"><p>Updated ' . $updated . ' options with non-standard autoload values to "yes".</p></div>';
        }
        
        echo '</div>';
    }
}

// Initialize the monitor
new OH_Autoload_Monitor();
