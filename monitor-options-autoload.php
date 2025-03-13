<?php
/**
 * monitor-options-autoload.php
 * Plugin to monitor and log changes to wp_options autoload values
 * Version: 1.0.0
 * 
 * @author Brian Chin
 * @package OctaHexa Utils
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Monitor option modifications and log abnormal autoload values
 */
function oh_monitor_option_operations() {
    // Monitor option additions
    add_action('added_option', function($option, $value, $autoload) {
        if ($autoload !== 'yes' && $autoload !== 'no') {
            error_log(sprintf(
                'ABNORMAL AUTOLOAD: Option "%s" added with autoload="%s" by %s',
                $option,
                $autoload,
                oh_get_caller_info()
            ));
        }
    }, 10, 3);
    
    // Monitor option updates that might change autoload
    add_action('update_option', function($option, $old_value, $value, $autoload = null) {
        if ($autoload !== null && $autoload !== 'yes' && $autoload !== 'no') {
            error_log(sprintf(
                'ABNORMAL AUTOLOAD: Option "%s" updated with autoload="%s" by %s',
                $option,
                $autoload,
                oh_get_caller_info()
            ));
        }
    }, 10, 4);
}
add_action('plugins_loaded', 'oh_monitor_option_operations');

/**
 * Get information about the function/file that triggered the option operation
 * 
 * @return string Caller information
 */
function oh_get_caller_info() {
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
    $caller = array();
    
    // Skip the first few entries which will be our own functions
    for ($i = 3; $i < count($trace); $i++) {
        if (isset($trace[$i]['class'])) {
            $caller[] = $trace[$i]['class'] . '::' . $trace[$i]['function'];
        } elseif (isset($trace[$i]['function'])) {
            $caller[] = $trace[$i]['function'];
        }
        
        if (isset($trace[$i]['file'])) {
            $file = str_replace(ABSPATH, '', $trace[$i]['file']);
            $caller[] = $file . ':' . $trace[$i]['line'];
            break;
        }
    }
    
    return implode(' via ', $caller);
}

/**
 * Add a diagnostic admin page
 */
function oh_add_options_autoload_diagnostics() {
    add_management_page(
        'Options Autoload Diagnostic',
        'Options Autoload',
        'manage_options',
        'oh-options-autoload',
        'oh_options_autoload_diagnostic_page'
    );
}
add_action('admin_menu', 'oh_add_options_autoload_diagnostics');

/**
 * Render the diagnostic admin page
 */
function oh_options_autoload_diagnostic_page() {
    global $wpdb;
    
    echo '<div class="wrap">';
    echo '<h1>WordPress Options Autoload Diagnostic</h1>';
    
    // Get autoload statistics
    $stats = $wpdb->get_results(
        "SELECT autoload, COUNT(*) as count FROM {$wpdb->options} GROUP BY autoload ORDER BY count DESC"
    );
    
    echo '<h2>Autoload Distribution</h2>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Autoload Value</th><th>Count</th></tr></thead>';
    echo '<tbody>';
    foreach ($stats as $row) {
        echo '<tr>';
        echo '<td>' . esc_html($row->autoload) . '</td>';
        echo '<td>' . esc_html($row->count) . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    
    // List abnormal autoload values
    $abnormal = $wpdb->get_results(
        "SELECT option_name, option_value, autoload 
         FROM {$wpdb->options} 
         WHERE autoload NOT IN ('yes', 'no')
         ORDER BY option_name
         LIMIT 50"
    );
    
    if (!empty($abnormal)) {
        echo '<h2>Options with Abnormal Autoload Values (First 50)</h2>';
        echo '<p>These options have autoload values other than "yes" or "no", which may cause issues.</p>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Option Name</th><th>Autoload Value</th><th>Option Value Preview</th></tr></thead>';
        echo '<tbody>';
        foreach ($abnormal as $row) {
            $value_preview = is_serialized($row->option_value) 
                ? '(serialized data)' 
                : substr($row->option_value, 0, 100) . (strlen($row->option_value) > 100 ? '...' : '');
            
            echo '<tr>';
            echo '<td>' . esc_html($row->option_name) . '</td>';
            echo '<td>' . esc_html($row->autoload) . '</td>';
            echo '<td>' . esc_html($value_preview) . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
    }
    
    echo '<p><a href="' . esc_url(add_query_arg('fix_autoload', '1')) . '" class="button button-primary">Fix Autoload Values</a></p>';
    
    // Handle fix request
    if (isset($_GET['fix_autoload']) && current_user_can('manage_options')) {
        $updated = $wpdb->query("UPDATE {$wpdb->options} SET autoload = 'yes' WHERE autoload = 'on'");
        $updated += $wpdb->query("UPDATE {$wpdb->options} SET autoload = 'yes' WHERE autoload = 'auto'");
        
        echo '<div class="notice notice-success"><p>Updated ' . $updated . ' options with abnormal autoload values to "yes".</p></div>';
        echo '<p><a href="' . esc_url(remove_query_arg('fix_autoload')) . '" class="button">Refresh</a></p>';
    }
    
    echo '</div>';
}
