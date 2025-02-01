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
    private $plugin_name;
    private $version;
    private $aws_client;

    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        add_action('admin_menu', array($this, 'add_plugin_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // AJAX handlers
        add_action('wp_ajax_wpbedrock_chat_message', array($this, 'ajax_chat_message'));
        add_action('wp_ajax_nopriv_wpbedrock_chat_message', array($this, 'ajax_chat_message'));
        add_action('wp_ajax_wpbedrock_tool_proxy', array($this, 'ajax_tool_proxy'));
        add_action('wp_ajax_nopriv_wpbedrock_tool_proxy', array($this, 'ajax_tool_proxy'));
        add_action('wp_ajax_wpbedrock_generate_image', array($this, 'ajax_generate_image'));
        add_action('wp_ajax_nopriv_wpbedrock_generate_image', array($this, 'ajax_generate_image'));
        add_action('wp_ajax_wpbedrock_upscale_image', array($this, 'ajax_upscale_image'));
        add_action('wp_ajax_nopriv_wpbedrock_upscale_image', array($this, 'ajax_upscale_image'));
        add_action('wp_ajax_wpbedrock_image_variation', array($this, 'ajax_image_variation'));
        add_action('wp_ajax_nopriv_wpbedrock_image_variation', array($this, 'ajax_image_variation'));
    }

    private function get_aws_client() {
        if (!$this->aws_client) {
            $aws_key = get_option('wpbedrock_aws_key');
            $aws_secret = get_option('wpbedrock_aws_secret');
            $aws_region = get_option('wpbedrock_aws_region', 'us-west-2');
            
            if (empty($aws_key) || empty($aws_secret)) {
                throw new Exception('AWS credentials not configured');
            }
            
            $this->aws_client = new WP_Bedrock_AWS($aws_key, $aws_secret, $aws_region);
        }
        return $this->aws_client;
    }

    public function add_plugin_admin_menu() {
        add_menu_page(
            'WP Bedrock',
            'WP Bedrock',
            'manage_options',
            'wp-bedrock',
            array($this, 'display_plugin_setup_page'),
            'dashicons-admin-generic',
            100
        );

        // remove_submenu_page('wp-bedrock', 'wp-bedrock');

        add_submenu_page(
            'wp-bedrock',
            'AI Chat',
            'AI Chat',
            'manage_options',
            'wp-bedrock_chatbot',
            array($this, 'display_chatbot_page')
        );

        add_submenu_page(
            'wp-bedrock',
            'Settings',
            'Settings',
            'manage_options',
            'wp-bedrock_settings',
            array($this, 'display_settings_page')
        );

        // add_submenu_page(
        //     'wp-bedrock',
        //     'Image Generation',
        //     'Image Generation',
        //     'manage_options',
        //     'wp-bedrock_image',
        //     array($this, 'display_image_page')
        // );
    }

    public function register_settings() {
        // AWS Settings
        register_setting('wp-bedrock_settings', 'wpbedrock_aws_key');
        register_setting('wp-bedrock_settings', 'wpbedrock_aws_secret');
        register_setting('wp-bedrock_settings', 'wpbedrock_aws_region');

        // Chat Settings
        register_setting('wp-bedrock_settings', 'wpbedrock_model_id');
        register_setting('wp-bedrock_settings', 'wpbedrock_temperature');
        register_setting('wp-bedrock_settings', 'wpbedrock_system_prompt');
        register_setting('wp-bedrock_settings', 'wpbedrock_chat_initial_message');
        register_setting('wp-bedrock_settings', 'wpbedrock_chat_placeholder');
        register_setting('wp-bedrock_settings', 'wpbedrock_enable_stream');
        register_setting('wp-bedrock_settings', 'wpbedrock_context_length');

        // Image Settings
        register_setting('wp-bedrock_settings', 'wpbedrock_image_model_id');
        register_setting('wp-bedrock_settings', 'wpbedrock_image_width');
        register_setting('wp-bedrock_settings', 'wpbedrock_image_height');
        register_setting('wp-bedrock_settings', 'wpbedrock_image_steps');
        register_setting('wp-bedrock_settings', 'wpbedrock_image_cfg_scale');
        register_setting('wp-bedrock_settings', 'wpbedrock_image_style_preset');
        register_setting('wp-bedrock_settings', 'wpbedrock_image_negative_prompt');
        register_setting('wp-bedrock_settings', 'wpbedrock_image_quality');
    }

    public function enqueue_styles() {
        $screen = get_current_screen();
        if (strpos($screen->id, 'wp-bedrock') !== false) {
            wp_enqueue_style('dashicons');
            wp_enqueue_style(
                $this->plugin_name,
                plugin_dir_url(__FILE__) . 'css/wp-bedrock-admin.css',
                array('dashicons'),
                $this->version,
                'all'
            );
            wp_enqueue_style(
                $this->plugin_name . '-chatbot',
                plugin_dir_url(__FILE__) . 'css/wp-bedrock-chatbot.css',
                array('dashicons'),
                $this->version,
                'all'
            );
        }
    }

    public function enqueue_scripts() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'wp-bedrock') === false) {
            return;
        }

        // Base admin script
        wp_enqueue_script(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'js/wp-bedrock-admin.js',
            array('jquery'),
            $this->version,
            true
        );

        if (strpos($screen->id, 'wp-bedrock_chatbot') !== false) {
            $this->enqueue_chat_scripts();
        }
    }

    private function enqueue_chat_scripts() {
        wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_script('jquery-ui-dialog');

        // Chat dependencies
        wp_enqueue_style('highlight-js', plugin_dir_url(__FILE__) . 'css/github.min.css', array(), $this->version);
        wp_enqueue_script('highlight-js', plugin_dir_url(__FILE__) . 'js/highlight.min.js', array('jquery'), $this->version, true);
        wp_enqueue_script('markdown-it', plugin_dir_url(__FILE__) . 'js/markdown-it.min.js', array('jquery'), $this->version, true);

        // Initialize libraries
        wp_add_inline_script('highlight-js', 'window.hljs = hljs;', 'after');
        wp_add_inline_script('markdown-it', 'window.markdownit = markdownit;', 'after');

        // Chat components
        wp_enqueue_script($this->plugin_name . '-api', plugin_dir_url(__FILE__) . 'js/wp-bedrock-api.js', array('jquery'), $this->version, true);
        wp_enqueue_script($this->plugin_name . '-response-handler', plugin_dir_url(__FILE__) . 'js/wp-bedrock-response-handler.js', array('jquery'), $this->version, true);
        wp_enqueue_script($this->plugin_name . '-chat-manager', plugin_dir_url(__FILE__) . 'js/wp-bedrock-chat-manager.js', array('jquery'), $this->version, true);

        // Main chat script
        wp_enqueue_script(
            $this->plugin_name . '-chatbot',
            plugin_dir_url(__FILE__) . 'js/wp-bedrock-chatbot.js',
            array('jquery', 'jquery-ui-dialog', 'highlight-js', 'markdown-it'),
            $this->version . '.' . time(),
            true
        );

        // Chat configuration
        wp_localize_script($this->plugin_name . '-chatbot', 'wpbedrock', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpbedrock_chat_nonce'),
            'initial_message' => get_option('wpbedrock_chat_initial_message', 'Hello! How can I help you today?'),
            'placeholder' => get_option('wpbedrock_chat_placeholder', 'Type your message here...'),
            'enable_stream' => get_option('wpbedrock_enable_stream', '1') === '1',
            'default_model' => get_option('wpbedrock_model_id', 'anthropic.claude-3-haiku-20240307-v1:0'),
            'default_temperature' => floatval(get_option('wpbedrock_temperature', '0.7')),
            'default_system_prompt' => get_option('wpbedrock_system_prompt', 'You are a helpful AI assistant.'),
            'plugin_url' => plugin_dir_url(__FILE__),
            'tools' => json_decode(file_get_contents(plugin_dir_path(__FILE__) . '../includes/tools.json'), true)
        ));
    }

    // AJAX Handlers
    public function ajax_chat_message() {
        check_ajax_referer('wpbedrock_chat_nonce', 'nonce');
        
        try {
            $result = $this->get_aws_client()->handle_chat_message($_REQUEST);
            if ($result['success']) {
                wp_send_json_success($result['data']);
            } else {
                wp_send_json_error($result['error']);
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_generate_image() {
        check_ajax_referer('wpbedrock_chat_nonce', 'nonce');

        try {
            $prompt = sanitize_textarea_field($_REQUEST['prompt'] ?? '');
            if (empty($prompt)) {
                throw new Exception('Prompt is required');
            }

            $model_id = sanitize_text_field($_REQUEST['model'] ?? get_option('wpbedrock_image_model_id'));
            $images = $this->get_aws_client()->generate_image($prompt, $model_id);
            
            wp_send_json_success(['images' => $images]);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_upscale_image() {
        check_ajax_referer('wpbedrock_chat_nonce', 'nonce');

        try {
            $image_data = sanitize_text_field($_REQUEST['image'] ?? '');
            if (empty($image_data)) {
                throw new Exception('Image data is required');
            }

            $result = $this->get_aws_client()->upscale_image($image_data);
            wp_send_json_success(['image' => $result]);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_image_variation() {
        check_ajax_referer('wpbedrock_chat_nonce', 'nonce');

        try {
            $image_data = sanitize_text_field($_REQUEST['image'] ?? '');
            if (empty($image_data)) {
                throw new Exception('Image data is required');
            }

            $result = $this->get_aws_client()->create_image_variation($image_data);
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_tool_proxy() {
        check_ajax_referer('wpbedrock_chat_nonce', 'nonce');

        try {
            $url = sanitize_url($_REQUEST['url'] ?? '');
            $method = strtoupper(sanitize_text_field($_REQUEST['method'] ?? 'GET'));
            $params = $_REQUEST['params'] ?? [];

            if (empty($url)) {
                throw new Exception('URL is required');
            }

            $args = [
                'method' => $method,
                'timeout' => 30,
                'redirection' => 5,
                'httpversion' => '1.1',
                'headers' => [
                    'User-Agent' => 'WordPress/' . get_bloginfo('version'),
                    'Accept' => '*/*'
                ]
            ];

            // Handle both GET and POST requests properly
            if ($method === 'POST') {
                $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
                // Ensure params are properly encoded for POST
                $args['body'] = is_array($params) ? http_build_query($params) : $params;
            } else {
                // For GET requests, ensure params are properly encoded in URL
                $url = add_query_arg(is_array($params) ? array_map('urlencode', $params) : $params, $url);
            }

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $status = wp_remote_retrieve_response_code($response);

            if ($status !== 200) {
                throw new Exception('Tool request failed with status: ' . $status);
            }

            wp_send_json_success(json_decode($body));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    // Display Pages
    public function display_plugin_setup_page() {
        include_once('partials/wp-bedrock-admin-display.php');
    }

    public function display_settings_page() {
        include_once('partials/wp-bedrock-admin-settings.php');
    }

    public function display_chatbot_page() {
        $aws_key = get_option('wpbedrock_aws_key');
        $aws_secret = get_option('wpbedrock_aws_secret');
        
        if (empty($aws_key) || empty($aws_secret)) {
            add_settings_error(
                'wpbedrock_messages',
                'wpbedrock_aws_credentials_missing',
                'AWS credentials are not configured. Please configure them in the <a href="' . 
                admin_url('admin.php?page=wp-bedrock_settings') . '">settings page</a>.',
                'error'
            );
            settings_errors('wpbedrock_messages');
        }
        
        include_once('partials/wp-bedrock-admin-chatbot.php');
    }

    public function display_image_page() {
        include_once('partials/wp-bedrock-admin-image.php');
    }
}
