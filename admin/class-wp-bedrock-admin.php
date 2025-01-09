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

        add_action('admin_menu', array($this, 'add_plugin_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_wpbedrock_chat_message', array($this, 'handle_chat_message'));
        add_action('wp_ajax_nopriv_wpbedrock_chat_message', array($this, 'handle_chat_message'));
        add_action('wp_ajax_wpbedrock_generate_image', array($this, 'handle_image_generation'));
        add_action('wp_ajax_nopriv_wpbedrock_generate_image', array($this, 'handle_image_generation'));
        add_action('wp_ajax_wpbedrock_upscale_image', array($this, 'handle_image_upscale'));
        add_action('wp_ajax_nopriv_wpbedrock_upscale_image', array($this, 'handle_image_upscale'));
        add_action('wp_ajax_wpbedrock_image_variation', array($this, 'handle_image_variation'));
        add_action('wp_ajax_nopriv_wpbedrock_image_variation', array($this, 'handle_image_variation'));
    }

    /**
     * Register the stylesheets for the admin area.
     */
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

    /**
     * Register the JavaScript for the admin area.
     */
    public function enqueue_scripts() {
        $screen = get_current_screen();
        if (!$screen) return;

        // Image generation models
        $image_models = array(
            // Bedrock Stable Diffusion Models
            array(
                'id' => 'stability.stable-diffusion-xl-v3-large',
                'name' => 'SD3 Large',
                'description' => 'Most capable SD3 model',
                'max_size' => 1024,
                'type' => 'bedrock-sd'
            ),
            array(
                'id' => 'stability.stable-diffusion-xl-v3-medium',
                'name' => 'SD3 Medium',
                'description' => 'Balanced SD3 model',
                'max_size' => 1024,
                'type' => 'bedrock-sd'
            ),
            array(
                'id' => 'stability.stable-diffusion-xl-v3-large-turbo',
                'name' => 'SD3 Large Turbo',
                'description' => 'Fastest SD3 model',
                'max_size' => 1024,
                'type' => 'bedrock-sd'
            ),
            array(
                'id' => 'stability.stable-image-core-v1',
                'name' => 'Stable Image Core',
                'description' => 'Core image generation model',
                'max_size' => 1024,
                'type' => 'bedrock-sd'
            ),
            array(
                'id' => 'stability.stable-image-ultra-v1',
                'name' => 'Stable Image Ultra',
                'description' => 'Ultra high quality model',
                'max_size' => 1024,
                'type' => 'bedrock-sd'
            ),
            // Bedrock Titan Models
            array(
                'id' => 'amazon.titan-image-generator-v1',
                'name' => 'Titan Image Generator v1',
                'description' => 'First generation Titan model',
                'max_size' => 1792,
                'type' => 'bedrock-titan'
            ),
            array(
                'id' => 'amazon.titan-image-generator-v2',
                'name' => 'Titan Image Generator v2',
                'description' => 'Enhanced Titan model',
                'max_size' => 1792,
                'type' => 'bedrock-titan'
            ),
            // Bedrock Nova Canvas Models
            array(
                'id' => 'amazon.nova-canvas-v1',
                'name' => 'Nova Canvas v1',
                'description' => 'Nova Canvas image generation',
                'max_size' => 1792,
                'type' => 'bedrock-nova'
            ),
            array(
                'id' => 'amazon.nova-reel-v1',
                'name' => 'Nova Reel',
                'description' => 'Nova Reel image generation',
                'max_size' => 1792,
                'type' => 'bedrock-nova'
            )
        );

        if (strpos($screen->id, 'wp-bedrock') !== false) {
            wp_enqueue_script(
                $this->plugin_name,
                plugin_dir_url(__FILE__) . 'js/wp-bedrock-admin.js',
                array('jquery'),
                $this->version,
                true
            );
        }

        // Image page scripts are handled in display_image_page()
        if (strpos($screen->id, 'wp-bedrock_image') !== false) {
            return;
        }

        if (strpos($screen->id, 'wp-bedrock_chatbot') !== false) {
            // Enqueue jQuery UI dialog first
            wp_enqueue_style('wp-jquery-ui-dialog');
            wp_enqueue_script('jquery-ui-dialog');

            // Register and enqueue styles first
            wp_enqueue_style(
                'highlight-js',
                plugin_dir_url(__FILE__) . 'css/github.min.css',
                array(),
                $this->version,
                'all'
            );

            // Register scripts with proper dependencies
            wp_register_script(
                'highlight-js',
                plugin_dir_url(__FILE__) . 'js/highlight.min.js',
                array('jquery'),
                $this->version,
                true // Load in footer
            );

            wp_register_script(
                'markdown-it',
                plugin_dir_url(__FILE__) . 'js/markdown-it.min.js',
                array('jquery'),
                $this->version,
                true // Load in footer
            );

            // Enqueue scripts in order and add initialization code
            wp_enqueue_script('highlight-js');
            wp_add_inline_script('highlight-js', 'window.hljs = hljs;', 'after');

            wp_enqueue_script('markdown-it');
            wp_add_inline_script('markdown-it', 'window.markdownit = markdownit;', 'after');

            // Add debug logging
            wp_add_inline_script('highlight-js', 'console.log("[WP Bedrock] highlight.js loaded");', 'after');
            wp_add_inline_script('markdown-it', 'console.log("[WP Bedrock] markdown-it loaded");', 'after');

            // Load chatbot script with all dependencies
            wp_enqueue_script(
                $this->plugin_name . '-chatbot',
                plugin_dir_url(__FILE__) . 'js/wp-bedrock-chatbot.js',
                array('jquery', 'jquery-ui-dialog', 'highlight-js', 'markdown-it'),
                $this->version,
                true // Load in footer to ensure all dependencies are loaded first
            );

            // Add initialization check and error handling
            wp_add_inline_script($this->plugin_name . '-chatbot', '
                function checkDependencies() {
                    var missing = [];
                    if (typeof jQuery === "undefined") missing.push("jQuery");
                    if (typeof markdownit === "undefined") missing.push("markdown-it");
                    if (typeof hljs === "undefined") missing.push("highlight.js");
                    return missing;
                }

                function initializeChat() {
                    var missing = checkDependencies();
                    if (missing.length > 0) {
                        console.error("[WP Bedrock] Missing required libraries:", missing.join(", "));
                        var container = document.querySelector(".chat-container");
                        if (container) {
                            container.innerHTML = "<div class=\"error-message\" style=\"padding: 20px; color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin: 10px;\">" +
                                "<h3 style=\"margin-top: 0;\">Error: Chat Initialization Failed</h3>" +
                                "<p>Missing required libraries: " + missing.join(", ") + "</p>" +
                                "<p>Please try refreshing the page. If the error persists, contact your administrator.</p>" +
                                "</div>";
                        }
                        return false;
                    }
                    return true;
                }

                // Try to initialize immediately
                if (document.readyState === "complete" || document.readyState === "interactive") {
                    initializeChat();
                } else {
                    document.addEventListener("DOMContentLoaded", initializeChat);
                }

                // Add a fallback check
                setTimeout(function() {
                    initializeChat();
                }, 2000);
            ', 'before');

            // Available models grouped by provider
            $models = array(
                // Claude 3 Series
                array(
                    'id' => 'anthropic.claude-3-opus-20240229-v1:0',
                    'name' => 'Claude 3 Opus',
                    'description' => 'Most capable model, best for complex tasks',
                    'context_length' => 200000
                ),
                array(
                    'id' => 'anthropic.claude-3-sonnet-20240229-v1:0',
                    'name' => 'Claude 3 Sonnet',
                    'description' => 'Balanced performance and capabilities',
                    'context_length' => 200000
                ),
                array(
                    'id' => 'anthropic.claude-3-haiku-20240307-v1:0',
                    'name' => 'Claude 3 Haiku',
                    'description' => 'Fast and efficient, good for everyday tasks',
                    'context_length' => 200000
                ),
                array(
                    'id' => 'us.anthropic.claude-3-5-haiku-20241022-v1:0',
                    'name' => 'Claude 3.5 Haiku',
                    'description' => 'Latest Haiku model with improved capabilities',
                    'context_length' => 200000
                ),
                array(
                    'id' => 'us.anthropic.claude-3-5-sonnet-20241022-v2:0',
                    'name' => 'Claude 3.5 Sonnet',
                    'description' => 'Latest Sonnet model with improved capabilities',
                    'context_length' => 200000
                ),

                // AWS Nova Series
                array(
                    'id' => 'us.amazon.nova-micro-v1:0',
                    'name' => 'Nova Micro',
                    'description' => 'Lightweight model for simple tasks',
                    'context_length' => 4000
                ),
                array(
                    'id' => 'us.amazon.nova-lite-v1:0',
                    'name' => 'Nova Lite',
                    'description' => 'Balanced performance for general use',
                    'context_length' => 8000
                ),
                array(
                    'id' => 'us.amazon.nova-pro-v1:0',
                    'name' => 'Nova Pro',
                    'description' => 'Advanced model for complex tasks',
                    'context_length' => 16000
                ),

                // Llama 3 Series
                array(
                    'id' => 'us.meta.llama3-1-8b-instruct-v1:0',
                    'name' => 'Llama 3 1.8B Instruct',
                    'description' => 'Compact model for basic tasks',
                    'context_length' => 4096
                ),
                array(
                    'id' => 'us.meta.llama3-1-70b-instruct-v1:0',
                    'name' => 'Llama 3 1.70B Instruct',
                    'description' => 'Large model for advanced tasks',
                    'context_length' => 4096
                ),
                array(
                    'id' => 'us.meta.llama3-2-11b-instruct-v1:0',
                    'name' => 'Llama 3 2.11B Instruct',
                    'description' => 'Mid-size model with good performance',
                    'context_length' => 4096
                ),
                array(
                    'id' => 'us.meta.llama3-2-90b-instruct-v1:0',
                    'name' => 'Llama 3 2.90B Instruct',
                    'description' => 'Large model with enhanced capabilities',
                    'context_length' => 4096
                ),
                array(
                    'id' => 'us.meta.llama3-3-70b-instruct-v1:0',
                    'name' => 'Llama 3 3.70B Instruct',
                    'description' => 'Latest large model with best performance',
                    'context_length' => 4096
                ),

                // Mistral Series
                array(
                    'id' => 'mistral.mistral-large-2402-v1:0',
                    'name' => 'Mistral Large 2402',
                    'description' => 'Advanced model with strong performance',
                    'context_length' => 32768
                ),
                array(
                    'id' => 'mistral.mistral-large-2407-v1:0',
                    'name' => 'Mistral Large 2407',
                    'description' => 'Latest model with improved capabilities',
                    'context_length' => 32768
                )
            );

            wp_localize_script(
                $this->plugin_name . '-chatbot',
                'wpbedrock_chat',
                array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wpbedrock_chat_nonce'),
                    'initial_message' => get_option('wpbedrock_chat_initial_message', 'Hello! How can I help you today?'),
                    'placeholder' => get_option('wpbedrock_chat_placeholder', 'Type your message here...'),
                    'enable_stream' => get_option('wpbedrock_enable_stream', '1') === '1',
                    'context_length' => intval(get_option('wpbedrock_context_length', '4')),
                    'models' => $models,
                    'default_model' => get_option('wpbedrock_model_id', 'anthropic.claude-3-haiku-20240307-v1:0'),
                    'default_temperature' => floatval(get_option('wpbedrock_temperature', '0.7')),
                    'default_system_prompt' => get_option('wpbedrock_system_prompt', 'You are a helpful AI assistant. Respond to user queries in a clear and concise manner.'),
                    'plugin_url' => plugin_dir_url(__FILE__)
                )
            );
        }
    }

    /**
     * Add menu item
     */
    public function add_plugin_admin_menu() {
        add_menu_page(
            'Bedrock AI Agent',
            'Bedrock AI Agent',
            'manage_options',
            $this->plugin_name,
            array($this, 'display_plugin_setup_page'),
            'dashicons-format-chat',
            90
        );

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

        add_submenu_page(
            $this->plugin_name,
            'Image Generation',
            'Image Generation',
            'manage_options',
            $this->plugin_name . '_image',
            array($this, 'display_image_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // AWS Settings
        register_setting($this->plugin_name . '_settings', 'wpbedrock_aws_key');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_aws_secret');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_aws_region');

        // Chat Settings
        register_setting($this->plugin_name . '_settings', 'wpbedrock_model_id');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_temperature');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_max_tokens');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_system_prompt');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_chat_initial_message');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_chat_placeholder');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_enable_stream');
        register_setting($this->plugin_name . '_settings', 'wpbedrock_context_length', array(
            'type' => 'integer',
            'default' => 4,
            'sanitize_callback' => function($value) {
                $value = intval($value);
                return min(max($value, 1), 10);
            }
        ));

    }

    /**
     * Display pages
     */
    public function display_settings_page() {
        include plugin_dir_path(dirname(__FILE__)) . 'admin/partials/wp-bedrock-admin-settings.php';
    }

    public function display_chatbot_page() {
        $aws_key = get_option('wpbedrock_aws_key');
        $aws_secret = get_option('wpbedrock_aws_secret');
        
        if (empty($aws_key) || empty($aws_secret)) {
            add_settings_error(
                'wpbedrock_messages',
                'wpbedrock_aws_credentials_missing',
                'AWS credentials are not configured. Please configure them in the <a href="' . admin_url('admin.php?page=wp-bedrock_settings') . '">settings page</a>.',
                'error'
            );
            settings_errors('wpbedrock_messages');
        }
        
        include plugin_dir_path(dirname(__FILE__)) . 'admin/partials/wp-bedrock-admin-chatbot.php';
    }

    public function display_image_page() {
        // Get AWS credentials
        $aws_key = get_option('wpbedrock_aws_key');
        $aws_secret = get_option('wpbedrock_aws_secret');
        $aws_region = get_option('wpbedrock_aws_region', 'us-west-2');

        // Image generation models
        $image_models = array(
            // Bedrock Stable Diffusion Models
            array(
                'id' => 'stability.stable-diffusion-xl-v3-large',
                'name' => 'SD3 Large',
                'description' => 'Most capable SD3 model',
                'max_size' => 1024,
                'type' => 'bedrock-sd'
            ),
            array(
                'id' => 'stability.stable-diffusion-xl-v3-medium',
                'name' => 'SD3 Medium',
                'description' => 'Balanced SD3 model',
                'max_size' => 1024,
                'type' => 'bedrock-sd'
            ),
            array(
                'id' => 'stability.stable-diffusion-xl-v3-large-turbo',
                'name' => 'SD3 Large Turbo',
                'description' => 'Fastest SD3 model',
                'max_size' => 1024,
                'type' => 'bedrock-sd'
            ),
            array(
                'id' => 'stability.stable-image-core-v1',
                'name' => 'Stable Image Core',
                'description' => 'Core image generation model',
                'max_size' => 1024,
                'type' => 'bedrock-sd'
            ),
            array(
                'id' => 'stability.stable-image-ultra-v1',
                'name' => 'Stable Image Ultra',
                'description' => 'Ultra high quality model',
                'max_size' => 1024,
                'type' => 'bedrock-sd'
            ),
            // Bedrock Titan Models
            array(
                'id' => 'amazon.titan-image-generator-v1',
                'name' => 'Titan Image Generator v1',
                'description' => 'First generation Titan model',
                'max_size' => 1792,
                'type' => 'bedrock-titan'
            ),
            array(
                'id' => 'amazon.titan-image-generator-v2',
                'name' => 'Titan Image Generator v2',
                'description' => 'Enhanced Titan model',
                'max_size' => 1792,
                'type' => 'bedrock-titan'
            ),
            // Bedrock Nova Canvas Models
            array(
                'id' => 'amazon.nova-canvas-v1',
                'name' => 'Nova Canvas v1',
                'description' => 'Nova Canvas image generation',
                'max_size' => 1792,
                'type' => 'bedrock-nova'
            ),
            array(
                'id' => 'amazon.nova-reel-v1',
                'name' => 'Nova Reel',
                'description' => 'Nova Reel image generation',
                'max_size' => 1792,
                'type' => 'bedrock-nova'
            )
        );

        // Style presets
        $style_presets = array(
            'photographic' => 'Photographic',
            'digital-art' => 'Digital Art',
            'anime' => 'Anime',
            'cinematic' => 'Cinematic',
            'comic-book' => 'Comic Book',
            'fantasy-art' => 'Fantasy Art',
            'line-art' => 'Line Art',
            'analog-film' => 'Analog Film',
            'neon-punk' => 'Neon Punk',
            'isometric' => 'Isometric',
            'low-poly' => 'Low Poly',
            'origami' => 'Origami',
            'modeling-compound' => 'Modeling Compound',
            '3d-model' => '3D Model',
            'pixel-art' => 'Pixel Art',
            'tile-texture' => 'Tile Texture'
        );

        // Enqueue styles
        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            $this->plugin_name . '-admin',
            plugin_dir_url(__FILE__) . 'css/wp-bedrock-admin.css',
            array('dashicons'),
            $this->version,
            'all'
        );

        // Register and enqueue scripts
        if (!wp_script_is($this->plugin_name . '-image', 'registered')) {
            wp_register_script(
                $this->plugin_name . '-image',
                plugin_dir_url(__FILE__) . 'js/wp-bedrock-image.js',
                array('jquery'),
                $this->version,
                true
            );

            // Pass data to JavaScript
            wp_localize_script(
                $this->plugin_name . '-image',
                'wpbedrock_image',
                array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wpbedrock_image_nonce'),
                    'models' => $image_models,
                    'default_model' => get_option('wpbedrock_image_model_id', 'stability.stable-diffusion-xl-v1'),
                    'default_width' => intval(get_option('wpbedrock_image_width', '1024')),
                    'default_height' => intval(get_option('wpbedrock_image_height', '1024')),
                    'default_steps' => intval(get_option('wpbedrock_image_steps', '50')),
                    'default_cfg_scale' => floatval(get_option('wpbedrock_image_cfg_scale', '7')),
                    'default_style_preset' => get_option('wpbedrock_image_style_preset', 'photographic'),
                    'style_presets' => $style_presets
                )
            );
        }

        // Enqueue the script
        wp_enqueue_script($this->plugin_name . '-image');

        // Include template
        include plugin_dir_path(dirname(__FILE__)) . 'admin/partials/wp-bedrock-admin-image.php';
    }

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

        if (isset($data['text'])) {
            $data = [
                'bytes' => base64_encode(json_encode([
                    'type' => 'content_block_delta',
                    'delta' => [
                        'type' => 'text_delta',
                        'text' => $data['text']
                    ]
                ]))
            ];
        }

        error_log('Sending SSE message: ' . json_encode($data));
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        
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

        // Get parameters from request
        // Get request parameters with defaults from settings
        $message = isset($_REQUEST['message']) ? sanitize_textarea_field($_REQUEST['message']) : '';
        $image = isset($_REQUEST['image']) ? $_REQUEST['image'] : '';
        $model = isset($_REQUEST['model']) ? sanitize_text_field($_REQUEST['model']) : get_option('wpbedrock_model_id', 'anthropic.claude-3-haiku-20240307-v1:0');
        $temperature = isset($_REQUEST['temperature']) ? floatval($_REQUEST['temperature']) : floatval(get_option('wpbedrock_temperature', '0.7'));
        $system_prompt = isset($_REQUEST['system_prompt']) ? sanitize_textarea_field($_REQUEST['system_prompt']) : get_option('wpbedrock_system_prompt', 'You are a helpful AI assistant. Respond to user queries in a clear and concise manner.');
        
        if (empty($message) && empty($image)) {
            wp_send_json_error('Message or image is required');
        }

        if ($temperature < 0 || $temperature > 1) {
            wp_send_json_error('Temperature must be between 0 and 1');
        }

        $enable_stream = get_option('wpbedrock_enable_stream', '1') === '1';
        $is_stream = $enable_stream && isset($_REQUEST['stream']) && $_REQUEST['stream'] === '1';

        try {
            $aws_key = get_option('wpbedrock_aws_key');
            $aws_secret = get_option('wpbedrock_aws_secret');
            
            $model_id = $model;
            $temperature_value = $temperature;
            $max_tokens = intval(get_option('wpbedrock_max_tokens', '2000'));
            $system_prompt_value = $system_prompt;

            if (empty($aws_key) || empty($aws_secret)) {
                wp_send_json_error('AWS credentials not configured');
            }

            $history = isset($_REQUEST['history']) ? json_decode(stripslashes($_REQUEST['history']), true) : [];
            
            // Format messages array for Bedrock
            $messages = [];

            // Add system message
            if (!empty($system_prompt_value)) {
                $messages[] = [
                    'role' => 'system',
                    'content' => $system_prompt_value
                ];
            }

            // Add history messages with proper formatting
            if (is_array($history)) {
                foreach ($history as $msg) {
                    if (is_array($msg['content'])) {
                        // Handle messages with mixed content (text and images)
                        $content = [];
                        foreach ($msg['content'] as $item) {
                            if (isset($item['text'])) {
                                $content[] = ['text' => $item['text']];
                            } else if (isset($item['image_url'])) {
                                $content[] = ['image_url' => $item['image_url']];
                            }
                        }
                        $messages[] = [
                            'role' => $msg['role'],
                            'content' => $content
                        ];
                    } else {
                        // Handle simple text messages
                        $messages[] = [
                            'role' => $msg['role'],
                            'content' => $msg['content']
                        ];
                    }
                }
            }

            // Add current user message
            $current_content = [];
            if (!empty($message)) {
                $current_content[] = ['text' => $message];
            }
            if (!empty($image)) {
                $current_content[] = ['image_url' => ['url' => $image]];
            }

            $messages[] = [
                'role' => 'user',
                'content' => count($current_content) === 1 ? $current_content[0]['text'] : $current_content
            ];

            // Get selected tools and format based on model type
            $tools = isset($_REQUEST['tools']) ? json_decode(stripslashes($_REQUEST['tools']), true) : [];
            $requestBody = [
                'messages' => $messages,
                'max_tokens' => $max_tokens,
                'temperature' => $temperature_value,
                'top_p' => 0.9,
                'top_k' => 5
            ];

            // Format tools based on model type
            if (strpos($model_id, 'anthropic.claude') !== false) {
                // Claude format
                $requestBody['anthropic_version'] = 'bedrock-2023-05-31';
                if (!empty($tools)) {
                    $requestBody['tools'] = array_map(function($tool) {
                        return [
                            'name' => $tool['function']['name'] ?? '',
                            'description' => $tool['function']['description'] ?? '',
                            'input_schema' => $tool['function']['parameters'] ?? []
                        ];
                    }, $tools);
                }
            } elseif (strpos($model_id, 'mistral.mistral') !== false) {
                // Mistral format
                if (!empty($tools)) {
                    $requestBody['tool_choice'] = 'auto';
                    $requestBody['tools'] = array_map(function($tool) {
                        return [
                            'type' => 'function',
                            'function' => [
                                'name' => $tool['function']['name'] ?? '',
                                'description' => $tool['function']['description'] ?? '',
                                'parameters' => $tool['function']['parameters'] ?? []
                            ]
                        ];
                    }, $tools);
                }
            } elseif (strpos($model_id, 'amazon.nova') !== false) {
                // Nova format
                if (!empty($tools)) {
                    $requestBody['toolConfig'] = [
                        'tools' => array_map(function($tool) {
                            return [
                                'toolSpec' => [
                                    'name' => $tool['function']['name'] ?? '',
                                    'description' => $tool['function']['description'] ?? '',
                                    'inputSchema' => [
                                        'json' => [
                                            'type' => 'object',
                                            'properties' => $tool['function']['parameters']['properties'] ?? [],
                                            'required' => $tool['function']['parameters']['required'] ?? []
                                        ]
                                    ]
                                ]
                            ];
                        }, $tools),
                        'toolChoice' => ['auto' => []]
                    ];
                }
            }

            // Debug logging
            error_log('Chat request parameters:');
            error_log('Message: ' . $message);
            error_log('Model: ' . $model_id);
            error_log('Temperature: ' . $temperature_value);
            error_log('System Prompt: ' . $system_prompt_value);
            error_log('History length: ' . count($messages));
            error_log('Messages: ' . json_encode($messages));

            require_once WPBEDROCK_PLUGIN_DIR . 'includes/class-wp-bedrock-aws.php';
            $aws_region = get_option('wpbedrock_aws_region', 'us-west-2');
            $bedrock = new \WPBEDROCK\WP_Bedrock_AWS($aws_key, $aws_secret, $aws_region);

            if ($is_stream) {
                // Disable output buffering and compression
                @ini_set('output_buffering', 'off');
                @ini_set('zlib.output_compression', false);
                @ini_set('implicit_flush', true);
                while (@ob_end_clean());
                
                // Set headers for SSE
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('Connection: keep-alive');
                header('X-Accel-Buffering: no');
                
                // Send initial response to establish connection
                echo "retry: 1000\n\n";
                flush();
                
            $bedrock->invoke_model(
                $messages,
                array_merge(
                    [
                        'model_id' => $model_id,
                    ],
                    $requestBody
                ),
                [],
                    true,
                    function($text) {
                        $this->send_sse_message(['text' => $text]);
                    }
                );

                $this->send_sse_message(['done' => true]);
                exit;
            } else {
                $response = $bedrock->invoke_model(
                    $messages,
                    [
                        'model_id' => $model_id,
                        'temperature' => $temperature_value,
                        'max_tokens' => $max_tokens
                    ]
                );
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

    /**
     * Handle image generation request
     */
    public function handle_image_generation() {
        check_ajax_referer('wpbedrock_image_nonce', 'nonce');

        $prompt = isset($_REQUEST['prompt']) ? sanitize_textarea_field($_REQUEST['prompt']) : '';
        if (empty($prompt)) {
            wp_send_json_error('Prompt is required');
        }

        try {
            $aws_key = get_option('wpbedrock_aws_key');
            $aws_secret = get_option('wpbedrock_aws_secret');
            
            if (empty($aws_key) || empty($aws_secret)) {
                wp_send_json_error('AWS credentials not configured');
            }

            $config = [
                'model_id' => isset($_REQUEST['model']) ? sanitize_text_field($_REQUEST['model']) : get_option('wpbedrock_image_model_id', 'stability.stable-diffusion-xl-v1'),
                'width' => isset($_REQUEST['width']) ? intval($_REQUEST['width']) : intval(get_option('wpbedrock_image_width', '1024')),
                'height' => isset($_REQUEST['height']) ? intval($_REQUEST['height']) : intval(get_option('wpbedrock_image_height', '1024')),
                'steps' => isset($_REQUEST['steps']) ? intval($_REQUEST['steps']) : intval(get_option('wpbedrock_image_steps', '50')),
                'cfg_scale' => isset($_REQUEST['cfg_scale']) ? floatval($_REQUEST['cfg_scale']) : floatval(get_option('wpbedrock_image_cfg_scale', '7')),
                'style_preset' => isset($_REQUEST['style_preset']) ? sanitize_text_field($_REQUEST['style_preset']) : get_option('wpbedrock_image_style_preset', 'photographic'),
                'negative_prompt' => isset($_REQUEST['negative_prompt']) ? sanitize_textarea_field($_REQUEST['negative_prompt']) : get_option('wpbedrock_image_negative_prompt', ''),
                'num_images' => isset($_REQUEST['num_images']) ? intval($_REQUEST['num_images']) : 1,
                'quality' => isset($_REQUEST['quality']) ? sanitize_text_field($_REQUEST['quality']) : get_option('wpbedrock_image_quality', 'standard'),
                'seed' => isset($_REQUEST['seed']) ? intval($_REQUEST['seed']) : -1
            ];

            require_once WPBEDROCK_PLUGIN_DIR . 'includes/class-wp-bedrock-aws.php';
            $aws_region = get_option('wpbedrock_aws_region', 'us-west-2');
            $bedrock = new \WPBEDROCK\WP_Bedrock_AWS($aws_key, $aws_secret, $aws_region);

            $images = $bedrock->generate_image($prompt, $config);
            wp_send_json_success(['images' => $images]);

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function handle_image_upscale() {
        check_ajax_referer('wpbedrock_image_nonce', 'nonce');

        $image = isset($_REQUEST['image']) ? sanitize_text_field($_REQUEST['image']) : '';
        if (empty($image)) {
            wp_send_json_error('Image data is required');
        }

        try {
            $aws_key = get_option('wpbedrock_aws_key');
            $aws_secret = get_option('wpbedrock_aws_secret');
            
            if (empty($aws_key) || empty($aws_secret)) {
                wp_send_json_error('AWS credentials not configured');
            }

            $scale = isset($_REQUEST['scale']) ? intval($_REQUEST['scale']) : 2;
            if ($scale !== 2 && $scale !== 4) {
                wp_send_json_error('Invalid scale factor. Must be 2 or 4.');
            }

            require_once WPBEDROCK_PLUGIN_DIR . 'includes/class-wp-bedrock-aws.php';
            $aws_region = get_option('wpbedrock_aws_region', 'us-west-2');
            $bedrock = new \WPBEDROCK\WP_Bedrock_AWS($aws_key, $aws_secret, $aws_region);

            $upscaled_image = $bedrock->upscale_image($image, $scale);
            wp_send_json_success(['image' => $upscaled_image]);

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function handle_image_variation() {
        check_ajax_referer('wpbedrock_image_nonce', 'nonce');

        $image = isset($_REQUEST['image']) ? sanitize_text_field($_REQUEST['image']) : '';
        if (empty($image)) {
            wp_send_json_error('Image data is required');
        }

        try {
            $aws_key = get_option('wpbedrock_aws_key');
            $aws_secret = get_option('wpbedrock_aws_secret');
            
            if (empty($aws_key) || empty($aws_secret)) {
                wp_send_json_error('AWS credentials not configured');
            }

            $config = [
                'strength' => isset($_REQUEST['strength']) ? floatval($_REQUEST['strength']) : 0.7,
                'seed' => isset($_REQUEST['seed']) ? intval($_REQUEST['seed']) : rand(0, 4294967295),
                'cfg_scale' => isset($_REQUEST['cfg_scale']) ? floatval($_REQUEST['cfg_scale']) : 7.0,
                'steps' => isset($_REQUEST['steps']) ? intval($_REQUEST['steps']) : 50
            ];

            require_once WPBEDROCK_PLUGIN_DIR . 'includes/class-wp-bedrock-aws.php';
            $aws_region = get_option('wpbedrock_aws_region', 'us-west-2');
            $bedrock = new \WPBEDROCK\WP_Bedrock_AWS($aws_key, $aws_secret, $aws_region);

            $variation = $bedrock->create_image_variation($image, $config);
            wp_send_json_success($variation);

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}
