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
        register_setting('wp-bedrock_settings', 'wpbedrock_aws_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_aws_secret', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_aws_region', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));

        // Chat Settings
        register_setting('wp-bedrock_settings', 'wpbedrock_model_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_temperature', array(
            'type' => 'number',
            'sanitize_callback' => array($this, 'sanitize_float')
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_system_prompt', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field'
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_chat_initial_message', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field'
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_chat_placeholder', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_enable_stream', array(
            'type' => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_boolean')
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_context_length', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint'
        ));

        // Image Settings
        register_setting('wp-bedrock_settings', 'wpbedrock_image_model_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_image_width', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint'
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_image_height', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint'
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_image_steps', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint'
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_image_cfg_scale', array(
            'type' => 'number',
            'sanitize_callback' => array($this, 'sanitize_float')
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_image_style_preset', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_image_negative_prompt', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field'
        ));
        register_setting('wp-bedrock_settings', 'wpbedrock_image_quality', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ));
    }

    public function enqueue_styles() {
        $screen = get_current_screen();
        if (strpos($screen->id, 'wp-bedrock') !== false) {
            wp_enqueue_style('dashicons');
            wp_enqueue_style('wp-jquery-ui-dialog');
            
            // Core admin styles
            wp_enqueue_style(
                $this->plugin_name,
                plugin_dir_url(__FILE__) . 'css/wp-bedrock-admin.css',
                array('dashicons'),
                $this->version,
                'all'
            );
            
            // Tools styles
            wp_enqueue_style(
                $this->plugin_name . '-tools',
                plugin_dir_url(__FILE__) . 'css/wp-bedrock-tools.css',
                array('dashicons'),
                $this->version,
                'all'
            );
            
            // Chatbot styles
            wp_enqueue_style(
                $this->plugin_name . '-chatbot',
                plugin_dir_url(__FILE__) . 'css/wp-bedrock-modern-chat.css',
                array('dashicons'),
                $this->version,
                'all'
            );
            
            // Previously inline styles
            wp_enqueue_style(
                $this->plugin_name . '-inline',
                plugin_dir_url(__FILE__) . 'css/wp-bedrock-inline.css',
                array('dashicons', 'wp-jquery-ui-dialog'),
                $this->version,
                'all'
            );
            
            // GitHub theme for code highlighting
            wp_enqueue_style(
                $this->plugin_name . '-github',
                plugin_dir_url(__FILE__) . 'css/github.min.css',
                array(),
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

        // Base admin scripts
        wp_enqueue_script(
            $this->plugin_name . '-admin',
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
            // Sanitize request data
            $request_data = array();
            if (isset($_REQUEST['requestBody'])) {
                $request_data['requestBody'] = sanitize_textarea_field($_REQUEST['requestBody']);
            }
            if (isset($_REQUEST['model_id'])) {
                $request_data['model_id'] = sanitize_text_field($_REQUEST['model_id']);
            }
            if (isset($_REQUEST['stream'])) {
                $request_data['stream'] = sanitize_text_field($_REQUEST['stream']) === '1' ? '1' : '0';
            }
            
            $result = $this->get_aws_client()->handle_chat_message($request_data);
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

    private function get_tool_definition($url) {
        $tools_json = file_get_contents(plugin_dir_path(__FILE__) . '../includes/tools.json');
        $tools = json_decode($tools_json, true);
        
        if (!$tools || !isset($tools['tools'])) {
            throw new Exception('Invalid tools configuration');
        }

        foreach ($tools['tools'] as $tool) {
            $server_url = rtrim($tool['servers'][0]['url'], '/');
            if (strpos($url, $server_url) === 0) {
                return $tool;
            }
        }

        throw new Exception('Tool not found for URL: ' . $url);
    }

    private function get_content_type($tool, $path, $method) {
        $operation = $tool['paths'][$path][$method] ?? null;
        if (!$operation) {
            return 'application/json';
        }

        // Check for produces/consumes in OpenAPI spec
        if (isset($operation['produces'][0])) {
            return $operation['produces'][0];
        }

        // Default to JSON
        return 'application/json';
    }

    public function ajax_tool_proxy() {
        check_ajax_referer('wpbedrock_chat_nonce', 'nonce');

        try {
            $url = sanitize_url($_REQUEST['url'] ?? '');
            $method = strtoupper(sanitize_text_field($_REQUEST['method'] ?? 'GET'));
            
            // Sanitize params array recursively
            $params = isset($_REQUEST['params']) ? $this->sanitize_array_recursive($_REQUEST['params']) : [];

            if (empty($url)) {
                throw new Exception('URL is required');
            }

            // Get tool definition from tools.json
            $tool = $this->get_tool_definition($url);
            
            // Get the path and operation from the URL
            $server_url = rtrim($tool['servers'][0]['url'], '/');
            $request_path = wp_parse_url($url, PHP_URL_PATH);
            
            // Find matching path and method from tool definition
            $matching_path = null;
            $supported_method = null;
            foreach ($tool['paths'] as $defined_path => $operations) {
                // Remove trailing slashes for comparison
                $clean_defined_path = rtrim($defined_path, '/');
                $clean_request_path = rtrim($request_path, '/');
                
                if ($clean_defined_path === $clean_request_path) {
                    $matching_path = $defined_path;
                    // Get the first supported method if none specified
                    $supported_method = $method === 'GET' ? 
                        (isset($operations['get']) ? 'get' : array_key_first($operations)) :
                        strtolower($method);
                    break;
                }
            }

            if (!$matching_path) {
                throw new Exception("Path not found in tool definition");
            }

            // Use the supported method
            $method = strtoupper($supported_method);

            // Verify method is supported
            if (!isset($tool['paths'][$matching_path][$supported_method])) {
                throw new Exception("Method $method not supported for path: $matching_path");
            }

            // Get operation details
            $operation = $tool['paths'][$matching_path][$supported_method];
            $content_type = $this->get_content_type($tool, $matching_path, $method);

            // Prepare request arguments
            $args = [
                'method' => $method,
                'timeout' => 30,
                'redirection' => 5,
                'httpversion' => '1.1',
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                    'DNT' => '1',
                    'Connection' => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1'
                ]
            ];

            // Handle parameters according to OpenAPI specification
            $query_params = [];
            $body_params = [];

            // First check for requestBody definition
            if (isset($operation['requestBody'])) {
                $content_type = array_key_first($operation['requestBody']['content']);
                $schema = $operation['requestBody']['content'][$content_type]['schema'];
                
                // Set content type header
                $args['headers']['Content-Type'] = $content_type;
                
                // For JSON request bodies, send parameters as JSON
                if ($content_type === 'application/json') {
                    $args['body'] = json_encode($params);
                }
            } 
            // If no requestBody, handle parameters
            else if (isset($operation['parameters']) && is_array($operation['parameters'])) {
                foreach ($operation['parameters'] as $param) {
                    if (isset($params[$param['name']])) {
                        $value = $params[$param['name']];
                        $param_in = $param['in'] ?? 'query';
                        
                        // Convert value based on schema type
                        if (isset($param['schema']['type']) && 
                            ($param['schema']['type'] === 'object' || $param['schema']['type'] === 'array')) {
                            $value = json_encode($value);
                        } else {
                            $value = (string)$value;
                        }

                        // Add parameter to appropriate collection based on 'in' property
                        if ($param_in === 'query') {
                            $query_params[$param['name']] = $value;
                        } else if ($param_in === 'body') {
                            $body_params[$param['name']] = $value;
                        }
                    }
                }

                // Always add query parameters to URL
                if (!empty($query_params)) {
                    $url = add_query_arg($query_params, $url);
                }

                // For POST requests with form parameters
                if ($method === 'POST' && empty($args['body'])) {
                    $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
                    // If we have body parameters, use those, otherwise use query parameters in the body
                    $body_data = !empty($body_params) ? $body_params : $query_params;
                    $args['body'] = http_build_query($body_data);
                }
            } else {
                // If no parameters or requestBody defined in OpenAPI spec, use all params as query params
                foreach ($params as $name => $value) {
                    $query_params[$name] = is_array($value) || is_object($value) ? 
                        json_encode($value) : (string)$value;
                }
                
                // Add query parameters to URL
                if (!empty($query_params)) {
                    $url = add_query_arg($query_params, $url);
                }
            }

            // Make the request
            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $status = wp_remote_retrieve_response_code($response);

            // Handle API responses
            switch ($status) {
                case 200:
                    if (strpos($url, 'code.leez.tech') !== false) {
                        // For code interpreter, return the response directly
                        wp_send_json_success(json_decode($body, true));
                    } else if ($content_type === 'application/json' || strpos($url, 'lite.duckduckgo.com') !== false) {
                        $decoded = json_decode($body);
                        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                            wp_send_json_success($body);
                        } else {
                            wp_send_json_success($decoded);
                        }
                    } else {
                        wp_send_json_success($body);
                    }
                    break;

                case 202:
                    wp_send_json([
                        'success' => false,
                        'status' => 202,
                        'message' => 'Request accepted, processing'
                    ]);
                    break;

                default:
                    throw new Exception("Request failed with status: $status");
            }
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
        // Enqueue required styles
        wp_enqueue_style(
            $this->plugin_name . '-tools',
            plugin_dir_url(__FILE__) . 'css/wp-bedrock-tools.css',
            array(),
            $this->version,
            'all'
        );

        // Load image models and style presets
        $image_models = array(
            array(
                'id' => 'amazon.titan-image-generator-v1',
                'name' => 'Titan Image Generator v1',
                'type' => 'bedrock-titan'
            ),
            array(
                'id' => 'stability.stable-diffusion-xl-v1',
                'name' => 'Stable Diffusion XL v1',
                'type' => 'bedrock-sd'
            )
        );

        $style_presets = array(
            'none' => __('None', 'wp-bedrock'),
            'photographic' => __('Photographic', 'wp-bedrock'),
            'digital-art' => __('Digital Art', 'wp-bedrock'),
            'comic-book' => __('Comic Book', 'wp-bedrock'),
            'fantasy-art' => __('Fantasy Art', 'wp-bedrock'),
            'line-art' => __('Line Art', 'wp-bedrock'),
            'analog-film' => __('Analog Film', 'wp-bedrock'),
            'cinematic' => __('Cinematic', 'wp-bedrock'),
            'enhance' => __('Enhance', 'wp-bedrock'),
            'pixel-art' => __('Pixel Art', 'wp-bedrock'),
            'anime' => __('Anime', 'wp-bedrock')
        );

        include_once('partials/wp-bedrock-admin-image.php');
    }

    /**
     * Sanitize a float value
     *
     * @param mixed $value The value to sanitize
     * @return float Sanitized float value
     */
    public function sanitize_float($value) {
        return filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }

    /**
     * Sanitize a boolean value
     *
     * @param mixed $value The value to sanitize
     * @return bool Sanitized boolean value
     */
    public function sanitize_boolean($value) {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Recursively sanitize an array of values
     *
     * @param array $array The array to sanitize
     * @return array The sanitized array
     */
    private function sanitize_array_recursive($array) {
        $sanitized = array();
        
        foreach ($array as $key => $value) {
            // Sanitize the key
            $clean_key = sanitize_text_field($key);
            
            if (is_array($value)) {
                // Recursively sanitize nested arrays
                $sanitized[$clean_key] = $this->sanitize_array_recursive($value);
            } else if (is_object($value)) {
                // Convert objects to arrays and sanitize
                $sanitized[$clean_key] = $this->sanitize_array_recursive((array)$value);
            } else {
                // Sanitize scalar values
                if (is_numeric($value)) {
                    // Preserve numeric types
                    $sanitized[$clean_key] = is_float($value) ? 
                        $this->sanitize_float($value) : 
                        absint($value);
                } else if (is_bool($value)) {
                    // Preserve boolean types
                    $sanitized[$clean_key] = $this->sanitize_boolean($value);
                } else if (is_string($value) && strlen($value) > 255) {
                    // Use textarea sanitization for longer strings
                    $sanitized[$clean_key] = sanitize_textarea_field($value);
                } else {
                    // Default to text field sanitization
                    $sanitized[$clean_key] = sanitize_text_field($value);
                }
            }
        }
        
        return $sanitized;
    }
}
