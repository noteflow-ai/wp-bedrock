<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @since      1.0.0
 * @package    WP_Bedrock
 * @subpackage WP_Bedrock/admin
 */

namespace WPBEDROCK;

use Exception;

class WP_Bedrock_Admin {

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    string    $plugin_name       The name of this plugin.
     * @param    string    $version    The version of this plugin.
     */
    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        // 添加管理菜单
        add_action('admin_menu', array($this, 'add_plugin_admin_menu'));
        
        // 注册设置
        add_action('admin_init', array($this, 'register_settings'));

        // 注册AJAX处理函数
        add_action('wp_ajax_wpbedrock_chat_message', array($this, 'handle_chat_message'));
        add_action('wp_ajax_nopriv_wpbedrock_chat_message', array($this, 'handle_chat_message'));
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_styles() {
        $screen = get_current_screen();
        if (strpos($screen->id, 'wp-bedrock') !== false) {
            wp_enqueue_style(
                $this->plugin_name,
                plugin_dir_url(__FILE__) . 'css/wp-bedrock-admin.css',
                array(),
                $this->version,
                'all'
            );
            wp_enqueue_style(
                $this->plugin_name . '-chatbot',
                plugin_dir_url(__FILE__) . 'css/wp-bedrock-chatbot.css',
                array(),
                $this->version,
                'all'
            );
        }
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts() {
        $screen = get_current_screen();
        if (!$screen) return;

        // 加载通用admin脚本
        if (strpos($screen->id, 'wp-bedrock') !== false) {
            wp_enqueue_script(
                $this->plugin_name,
                plugin_dir_url(__FILE__) . 'js/wp-bedrock-admin.js',
                array('jquery'),
                $this->version,
                true
            );
        }

        // 加载chatbot特定脚本
        if (strpos($screen->id, 'wp-bedrock_chatbot') !== false) {
            wp_enqueue_script(
                $this->plugin_name . '-chatbot',
                plugin_dir_url(__FILE__) . 'js/wp-bedrock-chatbot.js',
                array('jquery'),
                $this->version,
                true
            );

            wp_localize_script(
                $this->plugin_name . '-chatbot',
                'wpbedrock_chat',
                array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wpbedrock_chat_nonce'),
                    'initial_message' => get_option('wpbedrock_chat_initial_message', 'Hello! How can I help you today?'),
                    'placeholder' => get_option('wpbedrock_chat_placeholder', 'Type your message here...'),
                    'enable_stream' => get_option('wpbedrock_enable_stream', '1') === '1'
                )
            );
        }
    }

    /**
     * Add menu item
     */
    public function add_plugin_admin_menu() {
        add_menu_page(
            'WP Bedrock', // Page title
            'WP Bedrock', // Menu title
            'manage_options', // Capability
            $this->plugin_name, // Menu slug
            array($this, 'display_plugin_setup_page'), // Function to display the page
            'dashicons-format-chat', // Icon
            90 // Position
        );

        // 添加子菜单
        add_submenu_page(
            $this->plugin_name,
            'Settings',
            'Settings',
            'manage_options',
            $this->plugin_name . '_settings',
            array($this, 'display_settings_page')
        );

        add_submenu_page(
            $this->plugin_name,
            'Chatbot',
            'Chatbot',
            'manage_options',
            $this->plugin_name . '_chatbot',
            array($this, 'display_chatbot_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting($this->plugin_name . '_settings', 'wpbedrock_aws_key');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_aws_secret');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_aws_region');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_model_id');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_temperature');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_max_tokens');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_chat_initial_message');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_chat_placeholder');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_enable_stream');
    }

    /**
     * Display settings page
     */
    public function display_settings_page() {
        include plugin_dir_path(dirname(__FILE__)) . 'admin/partials/wp-bedrock-admin-settings.php';
    }

    /**
     * Display chatbot page
     */
    public function display_chatbot_page() {
        include plugin_dir_path(dirname(__FILE__)) . 'admin/partials/wp-bedrock-admin-chatbot.php';
    }

    /**
     * Render the settings page for this plugin.
     */
    public function display_plugin_setup_page() {
        include_once('partials/wp-bedrock-admin-display.php');
    }

    /**
     * Send SSE message
     */
    private function send_sse_message($data) {
        if (connection_status() !== CONNECTION_NORMAL) {
            return;
        }

        // Debug logging
        error_log('Sending SSE message: ' . json_encode($data));

        // 发送消息
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        
        // 强制刷新输出
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Handle chat message request
     */
    public function handle_chat_message() {
        check_ajax_referer('wpbedrock_chat_nonce', 'nonce');

        // Get message from request
        $message = isset($_REQUEST['message']) ? sanitize_textarea_field($_REQUEST['message']) : '';
        
        if (empty($message)) {
            wp_send_json_error('Message cannot be empty');
        }

        // Check if streaming is enabled
        $enable_stream = get_option('wpbedrock_enable_stream', '1') === '1';
        $is_stream = $enable_stream && isset($_REQUEST['stream']) && $_REQUEST['stream'] === '1';
        
        // Debug logging
        error_log('Stream enabled in settings: ' . ($enable_stream ? 'true' : 'false'));
        error_log('Stream requested: ' . (isset($_REQUEST['stream']) ? $_REQUEST['stream'] : 'not set'));
        error_log('Final stream decision: ' . ($is_stream ? 'true' : 'false'));

        try {
            // 获取设置
            $aws_key = get_option('wpbedrock_aws_key');
            $aws_secret = get_option('wpbedrock_aws_secret');
            $model_id = get_option('wpbedrock_model_id', 'anthropic.claude-3-haiku-20240307-v1:0'); // Default to Haiku as it's the most cost-effective
            $temperature = floatval(get_option('wpbedrock_temperature', '0.7'));
            $max_tokens = intval(get_option('wpbedrock_max_tokens', '2000'));

            if (empty($aws_key) || empty($aws_secret)) {
                wp_send_json_error('AWS credentials not configured');
            }

            // 调用Bedrock API
            require_once WPBEDROCK_PLUGIN_DIR . 'includes/class-wp-bedrock-aws.php';
            $aws_region = get_option('wpbedrock_aws_region', 'us-west-2');
            $bedrock = new \WPBEDROCK\WP_Bedrock_AWS($aws_key, $aws_secret, $aws_region);

            if ($is_stream) {
                // Disable WordPress output buffering
                remove_action('shutdown', 'wp_ob_end_flush_all', 1);
                
                // Clear any existing output buffers
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }

                // Prevent WordPress from buffering
                wp_ob_end_flush_all();
                
                // Set required headers
                header('Content-Type: text/event-stream; charset=utf-8');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Cache-Control: post-check=0, pre-check=0', false);
                header('Pragma: no-cache');
                header('Content-Encoding: none');
                header('Connection: keep-alive');
                header('X-Accel-Buffering: no'); // Disable Nginx buffering
                header('Access-Control-Allow-Origin: *'); // Allow CORS if needed

                // Flush headers immediately
                flush();
                
                // Initialize streaming response
                $bedrock->invoke_model(
                    $message, 
                    $model_id, 
                    $temperature, 
                    $max_tokens,
                    true, // Enable streaming
                    function($text) {
                        $this->send_sse_message(['text' => $text]);
                    }
                );

                // Send completion message
                $this->send_sse_message(['done' => true]);
                exit;
            } else {
                // Regular AJAX response
                $response = $bedrock->invoke_model($message, $model_id, $temperature, $max_tokens);
                wp_send_json_success($response);
            }
        } catch (Exception $e) {
            if ($is_stream) {
                $this->send_sse_message(['error' => $e->getMessage()]);
                exit;
            } else {
                wp_send_json_error($e->getMessage());
            }
        }
    }
}
