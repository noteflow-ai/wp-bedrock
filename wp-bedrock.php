<?php
/**
 * The plugin bootstrap file
 *
 * @wordpress-plugin
 * Plugin Name:       Bedrock AI Chat
 * Description:       WordPress plugin for Amazon Bedrock AI integration with conversation support
 * Version:          1.0.0
 * Author:           glay
 * License:          GPL-2.0+
 * License URI:      http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:      bedrock-ai-chat
 * Domain Path:      /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('WPBEDROCK_VERSION', '1.0.0');
define('WPBEDROCK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPBEDROCK_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load Composer's autoloader
$composer_autoload = WPBEDROCK_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

// Check if AWS SDK is available
if (!class_exists('Aws\Sdk')) {
    function wpbedrock_admin_notice_aws_sdk_missing() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('WP Bedrock requires AWS SDK PHP. Please run composer install in the plugin directory.', 'bedrock-ai-chat'); ?></p>
        </div>
        <?php
    }
    add_action('admin_notices', 'wpbedrock_admin_notice_aws_sdk_missing');
    return;
}

// Load AWS Bedrock client if not exists
if (!class_exists('\\WPBEDROCK\\WPBEDROCK_AWS')) {
    require_once WPBEDROCK_PLUGIN_DIR . 'includes/class-wp-bedrock-aws.php';
}

/**
 * The code that runs during plugin activation
 */
function activate_wp_bedrock() {
    require_once WPBEDROCK_PLUGIN_DIR . 'includes/class-wp-bedrock-activator.php';
    WPBEDROCK\WP_Bedrock_Activator::activate();
}

/**
 * The code that runs during plugin deactivation
 */
function deactivate_wp_bedrock() {
    require_once WPBEDROCK_PLUGIN_DIR . 'includes/class-wp-bedrock-deactivator.php';
    WPBEDROCK\WP_Bedrock_Deactivator::deactivate();
}

/**
 * The code that runs during plugin uninstall
 */
function uninstall_wp_bedrock() {
    global $wpdb;
    
    // Check if this is the last instance of the plugin
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    $all_plugins = get_plugins();
    $bedrock_plugins = 0;
    foreach ($all_plugins as $key => $plugin) {
        if (strpos($key, 'wp-bedrock') !== false) {
            $bedrock_plugins++;
        }
    }
    
    if ($bedrock_plugins == 1) {
        // Clean up database tables
        $tables = [
            $wpdb->prefix . 'wpbedrock',
            $wpdb->prefix . 'wpbedrock_conversations',
            $wpdb->prefix . 'wpbedrock_messages'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
    }
}

// Register hooks
register_activation_hook(__FILE__, 'activate_wp_bedrock');
register_deactivation_hook(__FILE__, 'deactivate_wp_bedrock');
register_uninstall_hook(__FILE__, 'uninstall_wp_bedrock');

/**
 * The core plugin class
 */
require_once WPBEDROCK_PLUGIN_DIR . 'includes/class-wp-bedrock.php';

/**
 * Begins execution of the plugin
 */
function run_wp_bedrock() {
    $plugin = new WPBEDROCK\WP_Bedrock();
    $plugin->run();
}

run_wp_bedrock();
