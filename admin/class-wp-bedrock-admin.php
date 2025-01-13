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
        add_action('wp_ajax_wpbedrock_tool_call', array($this, 'handle_tool_call'));
        add_action('wp_ajax_nopriv_wpbedrock_tool_call', array($this, 'handle_tool_call'));
        add_action('wp_ajax_wpbedrock_generate_image', array($this, 'handle_image_generation'));
        add_action('wp_ajax_nopriv_wpbedrock_generate_image', array($this, 'handle_image_generation'));
        add_action('wp_ajax_wpbedrock_upscale_image', array($this, 'handle_image_upscale'));
        add_action('wp_ajax_nopriv_wpbedrock_upscale_image', array($this, 'handle_image_upscale'));
        add_action('wp_ajax_wpbedrock_image_variation', array($this, 'handle_image_variation'));
        add_action('wp_ajax_nopriv_wpbedrock_image_variation', array($this, 'handle_image_variation'));
    }

    /**
     * Register the plugin admin menu
     */
    public function add_plugin_admin_menu() {
        add_menu_page(
            'WP Bedrock', // Page title
            'WP Bedrock', // Menu title
            'manage_options', // Capability
            'wp-bedrock', // Menu slug
            array($this, 'display_plugin_setup_page'), // Function to display the page
            'dashicons-admin-generic', // Icon
            100 // Position
        );

        // Remove default submenu item
        remove_submenu_page('wp-bedrock', 'wp-bedrock');

        add_submenu_page(
            'wp-bedrock', // Parent slug
            'Settings', // Page title
            'Settings', // Menu title
            'manage_options', // Capability
            'wp-bedrock_settings', // Menu slug for settings page
            array($this, 'display_settings_page') // Function to display the page
        );

        add_submenu_page(
            'wp-bedrock', // Parent slug
            'AI Chat', // Page title
            'AI Chat', // Menu title
            'manage_options', // Capability
            'wp-bedrock_chatbot', // Menu slug
            array($this, 'display_chatbot_page') // Function to display the page
        );

        add_submenu_page(
            'wp-bedrock', // Parent slug
            'Image Generation', // Page title
            'Image Generation', // Menu title
            'manage_options', // Capability
            'wp-bedrock_image', // Menu slug
            array($this, 'display_image_page') // Function to display the page
        );
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
                'id' => 'us.amazon.nova-canvas-v1',
                'name' => 'Nova Canvas v1',
                'description' => 'Nova Canvas image generation',
                'max_size' => 1792,
                'type' => 'bedrock-nova'
            ),
            array(
                'id' => 'us.amazon.nova-reel-v1',
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
            wp_add_inline_script('highlight-js', 'console.log("[AI Chat for Amazon Bedrock] highlight.js loaded");', 'after');
            wp_add_inline_script('markdown-it', 'console.log("[AI Chat for Amazon Bedrock] markdown-it loaded");', 'after');

            // Register API and handler scripts
            wp_register_script(
                $this->plugin_name . '-api',
                plugin_dir_url(__FILE__) . 'js/wp-bedrock-api.js',
                array('jquery'),
                $this->version,
                true
            );

            wp_register_script(
                $this->plugin_name . '-response-handler',
                plugin_dir_url(__FILE__) . 'js/wp-bedrock-response-handler.js',
                array('jquery', $this->plugin_name . '-api'),
                $this->version,
                true
            );

            wp_register_script(
                $this->plugin_name . '-chat-manager',
                plugin_dir_url(__FILE__) . 'js/wp-bedrock-chat-manager.js',
                array('jquery', $this->plugin_name . '-api', $this->plugin_name . '-response-handler'),
                $this->version,
                true
            );

            // Load chatbot script with all dependencies and unique version
            wp_enqueue_script(
                $this->plugin_name . '-chatbot',
                plugin_dir_url(__FILE__) . 'js/wp-bedrock-chatbot.js',
                array(
                    'jquery',
                    'jquery-ui-dialog',
                    'highlight-js',
                    'markdown-it',
                    $this->plugin_name . '-api',
                    $this->plugin_name . '-response-handler',
                    $this->plugin_name . '-chat-manager'
                ),
                $this->version . '.' . time(), // Add timestamp to prevent caching
                true
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
                        console.error("[AI Chat for Amazon Bedrock] Missing required libraries:", missing.join(", "));
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
                    'id' => 'us.us.amazon.nova-micro-v1:0',
                    'name' => 'Nova Micro',
                    'description' => 'Lightweight model for simple tasks',
                    'context_length' => 4000
                ),
                array(
                    'id' => 'us.us.amazon.nova-lite-v1:0',
                    'name' => 'Nova Lite',
                    'description' => 'Balanced performance for general use',
                    'context_length' => 8000
                ),
                array(
                    'id' => 'us.us.amazon.nova-pro-v1:0',
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
     * Load tool definitions from OpenAPI spec
     */
    private function load_tools() {
        $tools_json = file_get_contents(WPBEDROCK_PLUGIN_DIR . 'includes/tools.json');
        if (!$tools_json) {
            throw new Exception('Failed to load tools definition');
        }
        return json_decode($tools_json, true)['tools'];
    }

    /**
     * Find tool definition by name
     */
    private function find_tool($tools, $tool_name) {
        foreach ($tools as $tool) {
            if (strtolower($tool['info']['title']) === str_replace('_', ' ', $tool_name)) {
                return $tool;
            }
        }
        throw new Exception('Unknown tool: ' . $tool_name);
    }

    /**
     * Handle tool execution
     */
    private function handle_tool_execution($tool_name, $args) {
        try {
            // Load tool definitions
            $tools = $this->load_tools();
            $tool = $this->find_tool($tools, $tool_name);

            // Get server URL and path
            $base_url = $tool['servers'][0]['url'];
            $path = array_key_first($tool['paths']);
            $operation = $tool['paths'][$path][array_key_first($tool['paths'][$path])];

            // Validate required parameters
            if (isset($operation['parameters'])) {
                foreach ($operation['parameters'] as $param) {
                    if (isset($param['required']) && $param['required'] && !isset($args[$param['name']])) {
                        throw new Exception("Missing required parameter: {$param['name']}");
                    }
                }
            }

            // Process parameters based on OpenAPI spec
            $query_params = array();
            $body_params = array();
            $path_params = array();
            $header_params = array();

            if (isset($operation['parameters'])) {
                foreach ($operation['parameters'] as $param) {
                    if (!isset($args[$param['name']])) {
                        if (isset($param['required']) && $param['required']) {
                            throw new Exception("Missing required parameter: {$param['name']}");
                        }
                        continue;
                    }

                    $value = $args[$param['name']];
                    
                    // Add parameter to appropriate location
                    switch ($param['in']) {
                        case 'query':
                            $query_params[$param['name']] = $value;
                            break;
                        case 'path':
                            $path_params[$param['name']] = $value;
                            break;
                        case 'header':
                            $header_params[$param['name']] = $value;
                            break;
                        case 'body':
                            $body_params = array_merge($body_params, $value);
                            break;
                    }
                }
            }

            // Build URL with path parameters
            $url = $base_url . $path;
            foreach ($path_params as $key => $value) {
                $url = str_replace('{' . $key . '}', urlencode($value), $url);
            }

            // Build request options
            $request_options = array(
                'method' => strtoupper(array_key_first($tool['paths'][$path])),
                'headers' => array_merge(
                    array(
                        'Accept' => 'application/json, application/xml;q=0.9, text/plain;q=0.8',
                        'User-Agent' => 'WP-Bedrock/1.0'
                    ),
                    $header_params
                )
            );

            // Add body if needed
            if (!empty($body_params)) {
                $request_options['body'] = json_encode($body_params);
                $request_options['headers']['Content-Type'] = 'application/json';
            }

            // Make request
            $response = wp_remote_request(
                add_query_arg($query_params, $url),
                $request_options
            );

            if (is_wp_error($response)) {
                throw new Exception('Request failed: ' . $response->get_error_message());
            }

            // Get response content type and body
            $content_type = wp_remote_retrieve_header($response, 'content-type');
            $body = wp_remote_retrieve_body($response);

            // Parse response based on content type
            if (empty($body)) {
                return null;
            } else if (strpos($content_type, 'application/json') !== false) {
                $result = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid JSON response');
                }
                return $result;
            } else if (strpos($content_type, 'application/xml') !== false || strpos($content_type, 'text/xml') !== false) {
                $xml = simplexml_load_string($body);
                if (!$xml) {
                    throw new Exception('Invalid XML response');
                }
                return json_decode(json_encode($xml), true);
            } else {
                return $body;
            }
        } catch (Exception $e) {
            error_log('[WP Bedrock] Tool execution error: ' . $e->getMessage());
            throw $e;
        }
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

        // Get existing chat configuration
        $chat_config = array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpbedrock_chat_nonce'),
            'initial_message' => get_option('wpbedrock_chat_initial_message', 'Hello! How can I help you today?'),
            'placeholder' => get_option('wpbedrock_chat_placeholder', 'Type your message here...'),
            'enable_stream' => get_option('wpbedrock_enable_stream', '1') === '1',
            'context_length' => intval(get_option('wpbedrock_context_length', '4')),
            'default_model' => get_option('wpbedrock_model_id', 'anthropic.claude-3-haiku-20240307-v1:0'),
            'default_temperature' => floatval(get_option('wpbedrock_temperature', '0.7')),
            'default_system_prompt' => get_option('wpbedrock_system_prompt', 'You are a helpful AI assistant. Respond to user queries in a clear and concise manner.'),
            'plugin_url' => plugin_dir_url(__FILE__),
        );

        // Add chat configuration
        wp_localize_script(
            $this->plugin_name . '-chatbot',
            'wpbedrock_chat',
            $chat_config
        );
        
        include plugin_dir_path(dirname(__FILE__)) . 'admin/partials/wp-bedrock-admin-chatbot.php';
    }

    /**
     * Handle tool call request
     */
    public function handle_tool_call() {
        try {
            check_ajax_referer('wpbedrock_chat_nonce', 'nonce');

            if (!isset($_POST['tool']) || !isset($_POST['args'])) {
                throw new Exception('Missing required parameters');
            }

            $tool = sanitize_text_field($_POST['tool']);
            $args = json_decode(stripslashes($_POST['args']), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid arguments format');
            }

            $result = $this->handle_tool_execution($tool, $args);
            wp_send_json_success($result);

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
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
                'id' => 'us.amazon.nova-canvas-v1',
                'name' => 'Nova Canvas v1',
                'description' => 'Nova Canvas image generation',
                'max_size' => 1792,
                'type' => 'bedrock-nova'
            ),
            array(
                'id' => 'us.amazon.nova-reel-v1',
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
     * Display the settings page
     */
    public function display_settings_page() {
        include_once('partials/wp-bedrock-admin-settings.php');
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // Register the settings page
        register_setting(
            'wp-bedrock_settings', // Option group
            'wp-bedrock_settings', // Option name
            array(
                'type' => 'array',
                'description' => 'WP Bedrock Settings',
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'show_in_rest' => false,
            )
        );

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

    /**
     * Send SSE message
     */
    /**
     * Check server configuration for streaming support
     */
    /**
     * Set up environment for streaming response
     */
    private function setup_stream_environment() {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        // Remove WordPress filters
        remove_filter('wp_die_handler', '_default_wp_die_handler');
        remove_all_filters('wp_die_handler');
        remove_all_filters('wp_die_ajax_handler');
        remove_all_filters('wp_headers');
        remove_all_filters('nocache_headers');

        // Clear output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Close session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Configure PHP settings
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('implicit_flush', true);
        @ini_set('output_buffering', 'Off');

        // Configure server settings
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', 1);
            @apache_setenv('dont-vary', 1);
        }
        if (function_exists('fastcgi_finish_request')) {
            @ini_set('fastcgi.logging', 'off');
        }

        // Set headers
        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');

            $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        }

        // Send initial SSE data
        echo "retry: 1000\n";
        echo ": keepalive\n\n";
        flush();

        // Register shutdown function
        register_shutdown_function(function() {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            echo "event: close\n";
            echo "data: {\"done\": true}\n\n";
            flush();
        });
    }

    /**
     * Extract content from streaming chunk based on model type
     */
    private function extract_chunk_content($chunk, $model_id) {
        if (!$chunk) return null;

        try {
            if (strpos($model_id, 'anthropic.claude') !== false) {
                return $chunk['delta']['text'] ?? null;
            } elseif (strpos($model_id, 'mistral.mistral') !== false) {
                return $chunk['choices'][0]['delta']['content'] ?? null;
            } elseif (strpos($model_id, 'us.amazon.nova') !== false) {
                return $chunk['outputText'] ?? null;
            } elseif (strpos($model_id, 'meta.llama') !== false) {
                return $chunk['generation'] ?? null;
            }
        } catch (Exception $e) {
            error_log('[AI Chat for Amazon Bedrock] Error extracting chunk content: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Extract content from complete response based on model type
     */
    private function extract_response_content($response, $model_id) {
        if (!$response) return '';

        try {
            if (strpos($model_id, 'anthropic.claude') !== false) {
                return $response['content'][0]['text'] ?? '';
            } elseif (strpos($model_id, 'mistral.mistral') !== false) {
                return $response['choices'][0]['message']['content'] ?? '';
            } elseif (strpos($model_id, 'us.amazon.nova') !== false) {
                return $response['results'][0]['outputText'] ?? '';
            } elseif (strpos($model_id, 'meta.llama') !== false) {
                return $response['generation'] ?? '';
            }
        } catch (Exception $e) {
            error_log('[AI Chat for Amazon Bedrock] Error extracting response content: ' . $e->getMessage());
        }
        return '';
    }

    /**
     * Check server configuration for streaming support
     */
    private function check_streaming_support() {
        $issues = [];
        
        // Check output buffering
        if (ob_get_level() > 0) {
            error_log('[AI Chat for Amazon Bedrock] Warning: Output buffering is active');
            $issues[] = 'output_buffering';
        }
        
        // Check compression
        if (ini_get('zlib.output_compression')) {
            error_log('[AI Chat for Amazon Bedrock] Warning: zlib.output_compression is enabled');
            $issues[] = 'zlib_compression';
        }
        
        // Check FastCGI
        if (function_exists('fastcgi_finish_request')) {
            if (ini_get('fastcgi.logging')) {
                error_log('[AI Chat for Amazon Bedrock] Warning: FastCGI logging is enabled');
                $issues[] = 'fastcgi_logging';
            }
        }
        
        // Check Apache mod_deflate
        if (function_exists('apache_get_modules') && in_array('mod_deflate', apache_get_modules())) {
            error_log('[AI Chat for Amazon Bedrock] Warning: Apache mod_deflate is enabled');
            $issues[] = 'apache_deflate';
        }
        
        return $issues;
    }

    private function send_sse_message($data) {
        if (connection_status() !== CONNECTION_NORMAL) {
            return;
        }

        // Check for streaming issues
        static $checked = false;
        if (!$checked) {
            $issues = $this->check_streaming_support();
            if (!empty($issues)) {
                error_log('[AI Chat for Amazon Bedrock] Streaming configuration issues detected: ' . implode(', ', $issues));
            }
            $checked = true;
        }

        // First log the raw text if present
        if (isset($data['text'])) {
            error_log('[AI Chat for Amazon Bedrock] Streaming content: ' . $data['text']);
            
            // Then encode it for SSE
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

        // Disable output buffering completely
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Handle chat message request
     */
    /**
     * Handle chat message request
     */
    public function handle_chat_message() {
        try {
            // Validate nonce
            check_ajax_referer('wpbedrock_chat_nonce', 'nonce');

            // Get request data
            $request_data = $_POST;
            $model_id = isset($request_data['model_id']) 
                ? sanitize_text_field($request_data['model_id']) 
                : get_option('wpbedrock_model_id', 'anthropic.claude-3-haiku-20240307-v1:0');

            // Parse request body from POST data
            $requestBody = null;
            if (!isset($_POST['requestBody'])) {
                error_log('[WP Bedrock] Request body is missing from POST data');
                error_log('[WP Bedrock] POST data: ' . print_r($_POST, true));
                throw new Exception('Request body is missing');
            }

            // Log raw request for debugging
            // error_log('[WP Bedrock] Raw request body: ' . $_POST['requestBody']);

            // WordPress automatically adds slashes, so we need to remove them
            $requestBody = json_decode(stripslashes($_POST['requestBody']), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error_msg = json_last_error_msg();
                error_log('[WP Bedrock] JSON decode error: ' . $error_msg);
                throw new Exception('Invalid request body: ' . $error_msg);
            }

            if (!$requestBody) {
                throw new Exception('Request body is required');
            }

            // Add tools to request body if provided
            if (isset($request_data['tools']) && is_string($request_data['tools'])) {
                $tools = json_decode($request_data['tools'], true);
                if (json_last_error() === JSON_ERROR_NONE && !empty($tools)) {
                    $requestBody['tools'] = $tools;
                }
            }

            // Get AWS credentials
            $aws_key = get_option('wpbedrock_aws_key');
            $aws_secret = get_option('wpbedrock_aws_secret');
            $aws_region = get_option('wpbedrock_aws_region', 'us-west-2');

            if (empty($aws_key) || empty($aws_secret)) {
                throw new Exception('AWS credentials not configured');
            }

            // Initialize AWS client
            require_once WPBEDROCK_PLUGIN_DIR . 'includes/class-wp-bedrock-aws.php';
            
            // Get configured region or use default
            $aws_region = get_option('wpbedrock_aws_region');
            if (empty($aws_region)) {
                $aws_region = 'us-west-2';
            }
            
            $bedrock = new \WPBEDROCK\WP_Bedrock_AWS($aws_key, $aws_secret, $aws_region);

            // Initialize streaming flag
            $enable_stream = get_option('wpbedrock_enable_stream', '1') === '1';
            $is_stream = false;
               // Check if model supports tools
               $supportsTools = (
                strpos($model_id, 'anthropic.claude-3') !== false ||
                strpos($model_id, 'anthropic.claude-3.5') !== false ||
                strpos($model_id, 'us.amazon.nova') !== false ||
                strpos($model_id, 'mistral.mistral') !== false
            );
            
            // Check if streaming is requested and enabled
            if ($enable_stream && isset($request_data['stream']) && $request_data['stream'] === '1') {
                //Sreaming
                $is_stream = true;
                $this->setup_stream_environment();
                
                try {
                    $bedrock->invoke_model($requestBody, $model_id, true, function($chunk) use ($model_id) {
                        $text = $this->extract_chunk_content($chunk, $model_id);
                        if ($text !== null) {
                            $this->send_sse_message(['text' => $text]);
                        }
                    });
                    
                    $this->send_sse_message(['done' => true]);
                } catch (Exception $e) {
                    error_log('[AI Chat for Amazon Bedrock] Stream error: ' . $e->getMessage());
                    $this->send_sse_message(['error' => $e->getMessage()]);
                }
                
                exit;
            } else {
                //Non-streaming
                $response = $bedrock->invoke_model($requestBody, $model_id);
                
                // Check for tool calls in the response
             

                if ($supportsTools && isset($response['content'])) {
                    foreach ($response['content'] as $content) {
                        if ($content['type'] === 'tool_use' || isset($content['tool_calls'])) {
                            try {
                                $toolName = isset($content['name']) ? $content['name'] : $content['tool_calls'][0]['function']['name'];
                                $toolInput = isset($content['input']) ? $content['input'] : 
                                    (isset($content['tool_calls'][0]['function']['arguments']) ? 
                                        json_decode($content['tool_calls'][0]['function']['arguments'], true) : array());
                                
                                error_log('[WP Bedrock] Executing tool: ' . $toolName . ' with input: ' . json_encode($toolInput));
                                $result = $this->handle_tool_execution($toolName, $toolInput);
                                error_log('[WP Bedrock] Tool execution result: ' . json_encode($result));

                                if (strpos($model_id, 'anthropic.claude') !== false) {
                                    // Claude format
                                    $requestBody['messages'][] = array(
                                        'role' => 'assistant',
                                        'content' => array(array(
                                            'type' => 'tool_use',
                                            'id' => isset($content['id']) ? $content['id'] : $content['tool_calls'][0]['id'],
                                            'name' => $toolName,
                                            'input' => $toolInput
                                        ))
                                    );
                                    $requestBody['messages'][] = array(
                                        'role' => 'user',
                                        'content' => array(array(
                                            'type' => 'tool_result',
                                            'tool_use_id' => isset($content['id']) ? $content['id'] : $content['tool_calls'][0]['id'],
                                            'content' => $result
                                        ))
                                    );
                                } elseif (strpos($model_id, 'mistral.mistral') !== false) {
                                    // Mistral format
                                    $requestBody['messages'][] = array(
                                        'role' => 'assistant',
                                        'content' => '',
                                        'tool_calls' => array(array(
                                            'id' => isset($content['id']) ? $content['id'] : $content['tool_calls'][0]['id'],
                                            'function' => array(
                                                'name' => $toolName,
                                                'arguments' => json_encode($toolInput)
                                            )
                                        ))
                                    );
                                    $requestBody['messages'][] = array(
                                        'role' => 'tool',
                                        'tool_call_id' => isset($content['id']) ? $content['id'] : $content['tool_calls'][0]['id'],
                                        'content' => $result
                                    );
                                } elseif (strpos($model_id, 'amazon.nova') !== false) {
                                    // Nova format
                                    $requestBody['messages'][] = array(
                                        'role' => 'assistant',
                                        'content' => array(array(
                                            'toolUse' => array(
                                                'toolUseId' => isset($content['id']) ? $content['id'] : $content['tool_calls'][0]['id'],
                                                'name' => $toolName,
                                                'input' => $toolInput
                                            )
                                        ))
                                    );
                                    $requestBody['messages'][] = array(
                                        'role' => 'user',
                                        'content' => array(array(
                                            'toolResult' => array(
                                                'toolUseId' => isset($content['id']) ? $content['id'] : $content['tool_calls'][0]['id'],
                                                'content' => array(array(
                                                    'json' => array(
                                                        'content' => $result
                                                    )
                                                ))
                                            )
                                        ))
                                    );
                                }                                
                                // Get final response with tool results
                                $finalResponse = $bedrock->invoke_model($requestBody, $model_id);
                                $content = $this->extract_response_content($finalResponse, $model_id);
                                wp_send_json_success(['content' => $content]);
                                return;
                            } catch (Exception $e) {
                                error_log('[WP Bedrock] Tool execution error: ' . $e->getMessage());
                                throw $e;
                            }
                        }
                    }
                }
                
                // No tool calls, return normal response
                $content = $this->extract_response_content($response, $model_id);
                wp_send_json_success(['content' => $content]);
            }
        } catch (Exception $e) {
            error_log('[WP Bedrock] Error: ' . $e->getMessage());
            
            // Initialize streaming flag
            $enable_stream = get_option('wpbedrock_enable_stream', '1') === '1';
            $is_stream = $enable_stream && isset($request_data['stream']) && $request_data['stream'] === '1';
            
            if ($is_stream) {
                $this->send_sse_message(['error' => $e->getMessage()]);
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
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


    /**
     * Sanitize settings
     */
    public function sanitize_settings($settings) {
        if (!is_array($settings)) {
            return array();
        }

        $sanitized = array();
        
        // AWS Settings
        $sanitized['wpbedrock_aws_key'] = isset($settings['wpbedrock_aws_key']) ? sanitize_text_field($settings['wpbedrock_aws_key']) : '';
        $sanitized['wpbedrock_aws_secret'] = isset($settings['wpbedrock_aws_secret']) ? sanitize_text_field($settings['wpbedrock_aws_secret']) : '';
        $sanitized['wpbedrock_aws_region'] = isset($settings['wpbedrock_aws_region']) ? sanitize_text_field($settings['wpbedrock_aws_region']) : 'us-west-2';

        // Chat Settings
        $sanitized['wpbedrock_model_id'] = isset($settings['wpbedrock_model_id']) ? sanitize_text_field($settings['wpbedrock_model_id']) : '';
        $sanitized['wpbedrock_temperature'] = isset($settings['wpbedrock_temperature']) ? floatval($settings['wpbedrock_temperature']) : 0.7;
        $sanitized['wpbedrock_system_prompt'] = isset($settings['wpbedrock_system_prompt']) ? sanitize_textarea_field($settings['wpbedrock_system_prompt']) : '';
        $sanitized['wpbedrock_chat_initial_message'] = isset($settings['wpbedrock_chat_initial_message']) ? sanitize_text_field($settings['wpbedrock_chat_initial_message']) : '';
        $sanitized['wpbedrock_chat_placeholder'] = isset($settings['wpbedrock_chat_placeholder']) ? sanitize_text_field($settings['wpbedrock_chat_placeholder']) : '';
        $sanitized['wpbedrock_enable_stream'] = isset($settings['wpbedrock_enable_stream']) ? '1' : '0';
        $sanitized['wpbedrock_context_length'] = isset($settings['wpbedrock_context_length']) ? intval($settings['wpbedrock_context_length']) : 4;

        // Image Settings
        $sanitized['wpbedrock_image_model_id'] = isset($settings['wpbedrock_image_model_id']) ? sanitize_text_field($settings['wpbedrock_image_model_id']) : '';
        $sanitized['wpbedrock_image_width'] = isset($settings['wpbedrock_image_width']) ? intval($settings['wpbedrock_image_width']) : 1024;
        $sanitized['wpbedrock_image_height'] = isset($settings['wpbedrock_image_height']) ? intval($settings['wpbedrock_image_height']) : 1024;
        $sanitized['wpbedrock_image_steps'] = isset($settings['wpbedrock_image_steps']) ? intval($settings['wpbedrock_image_steps']) : 50;
        $sanitized['wpbedrock_image_cfg_scale'] = isset($settings['wpbedrock_image_cfg_scale']) ? floatval($settings['wpbedrock_image_cfg_scale']) : 7.0;
        $sanitized['wpbedrock_image_style_preset'] = isset($settings['wpbedrock_image_style_preset']) ? sanitize_text_field($settings['wpbedrock_image_style_preset']) : 'photographic';
        $sanitized['wpbedrock_image_negative_prompt'] = isset($settings['wpbedrock_image_negative_prompt']) ? sanitize_textarea_field($settings['wpbedrock_image_negative_prompt']) : '';
        $sanitized['wpbedrock_image_quality'] = isset($settings['wpbedrock_image_quality']) ? sanitize_text_field($settings['wpbedrock_image_quality']) : 'standard';

        return $sanitized;
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
