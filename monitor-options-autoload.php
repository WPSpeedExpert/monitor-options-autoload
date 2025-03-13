<?php
/**
 * Plugin Name: Simple Options Autoload Monitor
 * Description: Basic tool to view and fix autoload values
 * Version: 1.0.0
 * Author: Brian Chin
 * Author URI: https://octahexa.com
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Add admin menu
function oh_simple_options_menu() {
    add_submenu_page(
        'tools.php',
        'Options Autoload',
        'Options Autoload',
        'manage_options',
        'simple-options-autoload',
        'oh_simple_options_page'
    );
}
add_action('admin_menu', 'oh_simple_options_menu');

// Render the admin page
function oh_simple_options_page() {
    global $wpdb;
    
    // Handle fix action
    if (isset($_GET['action']) && $_GET['action'] == 'fix' && current_user_can('manage_options')) {
        // Create backup
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}options_backup LIKE {$wpdb->prefix}options");
        $wpdb->query("INSERT INTO {$wpdb->prefix}options_backup SELECT * FROM {$wpdb->prefix}options");
        
        // Fix values
        $count1 = $wpdb->query("UPDATE {$wpdb->prefix}options SET autoload = 'yes' WHERE autoload = 'on'");
        $count2 = $wpdb->query("UPDATE {$wpdb->prefix}options SET autoload = 'yes' WHERE autoload = 'auto'");
        
        echo '<div class="notice notice-success is-dismissible"><p>Updated ' . ($count1 + $count2) . ' options. A backup was created in the table: ' . $wpdb->prefix . 'options_backup</p></div>';
    }
    
    // Display main page
    echo '<div class="wrap">';
    echo '<h1>WordPress Options Autoload Status</h1>';
    
    // Get stats
    $results = $wpdb->get_results("SELECT autoload, COUNT(*) as count FROM {$wpdb->prefix}options GROUP BY autoload");
    
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
    
    // Display fix button
    echo '<p style="margin-top:20px">';
    echo '<a href="' . esc_url(admin_url('tools.php?page=simple-options-autoload&action=fix')) . '" 
          class="button button-primary" 
          onclick="return confirm(\'This will update all options with autoload=\\\'on\\\' to autoload=\\\'yes\\\'. A backup will be created. Continue?\');">
          Fix Autoload Values</a>';
    echo '</p>';
    
    echo '</div>';
}
