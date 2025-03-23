<?php
/**
 * The plugin bootstrap file
 *
 * @wordpress-plugin
 * Plugin Name:       AI Chat for Amazon Bedrock
 * Description:       WordPress plugin for Amazon Bedrock AI integration with conversation support
 * Version:          1.0.3
 * Author:           glay
 * License:          GPL-2.0+
 * License URI:      http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:      ai-chat-for-amazon-bedrock
 * Domain Path:      /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('AICHAT_BEDROCK_VERSION', '1.0.3');
define('AICHAT_BEDROCK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AICHAT_BEDROCK_PLUGIN_URL', plugin_dir_url(__FILE__));

// For backward compatibility
define('WPBEDROCK_VERSION', AICHAT_BEDROCK_VERSION);
define('WPBEDROCK_PLUGIN_DIR', AICHAT_BEDROCK_PLUGIN_DIR);
define('WPBEDROCK_PLUGIN_URL', AICHAT_BEDROCK_PLUGIN_URL);

// Load Composer's autoloader
$composer_autoload = AICHAT_BEDROCK_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

// Load AWS Bedrock client if not exists
if (!class_exists('\\AICHAT_AMAZON_BEDROCK\\WP_Bedrock_AWS')) {
    require_once AICHAT_BEDROCK_PLUGIN_DIR . 'includes/class-wp-bedrock-aws.php';
}

/**
 * The code that runs during plugin activation
 */
function aichat_bedrock_activate() {
    try {
        require_once AICHAT_BEDROCK_PLUGIN_DIR . 'includes/class-wp-bedrock-activator.php';
        AICHAT_AMAZON_BEDROCK\WP_Bedrock_Activator::activate();
    } catch (\Exception $e) {
        error_log('AI Chat for Amazon Bedrock activation error: ' . $e->getMessage());
    }
}

/**
 * The code that runs during plugin deactivation
 */
function aichat_bedrock_deactivate() {
    require_once AICHAT_BEDROCK_PLUGIN_DIR . 'includes/class-wp-bedrock-deactivator.php';
    AICHAT_AMAZON_BEDROCK\WP_Bedrock_Deactivator::deactivate();
}

/**
 * The code that runs during plugin uninstall
 */
function aichat_bedrock_uninstall() {
    global $wpdb;
    
    // Check if this is the last instance of the plugin
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    $all_plugins = get_plugins();
    $bedrock_plugins = 0;
    foreach ($all_plugins as $key => $plugin) {
        if (strpos($key, 'ai-chat-for-amazon-bedrock') !== false || strpos($key, 'wp-bedrock') !== false) {
            $bedrock_plugins++;
        }
    }
    
    if ($bedrock_plugins == 1) {
        // Clean up database tables
        $tables = [
            $wpdb->prefix . 'aichat_bedrock',
            $wpdb->prefix . 'aichat_bedrock_conversations',
            $wpdb->prefix . 'aichat_bedrock_messages'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
    }
}

// Register hooks
register_activation_hook(__FILE__, 'aichat_bedrock_activate');
register_deactivation_hook(__FILE__, 'aichat_bedrock_deactivate');
register_uninstall_hook(__FILE__, 'aichat_bedrock_uninstall');

// For backward compatibility
function activate_wp_bedrock() {
    aichat_bedrock_activate();
}

function deactivate_wp_bedrock() {
    aichat_bedrock_deactivate();
}

function uninstall_wp_bedrock() {
    aichat_bedrock_uninstall();
}

/**
 * The core plugin class
 */
require_once AICHAT_BEDROCK_PLUGIN_DIR . 'includes/class-wp-bedrock.php';

/**
 * Begins execution of the plugin
 */
function aichat_bedrock_run() {
    $plugin = new AICHAT_AMAZON_BEDROCK\WP_Bedrock();
    $plugin->run();
}

aichat_bedrock_run();

// For backward compatibility
function run_wp_bedrock() {
    aichat_bedrock_run();
}
