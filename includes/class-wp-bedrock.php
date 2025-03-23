<?php
namespace AICHAT_AMAZON_BEDROCK;

class WP_Bedrock {
    private $plugin_name;
    private $version;
    private $admin;

    public function __construct() {
        $this->plugin_name = 'ai-chat-for-amazon-bedrock';
        $this->version = AICHAT_BEDROCK_VERSION;
        $this->load_dependencies();
        $this->setup_hooks();
    }

    private function load_dependencies() {
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-wp-bedrock-admin.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-wp-bedrock-widget.php';
        
        $this->admin = new WP_Bedrock_Admin($this->plugin_name, $this->version);
    }

    private function setup_hooks() {
        // Ajax handlers
        add_action('wp_ajax_aichat_bedrock_chat', array($this, 'handle_chat_request'));
        add_action('wp_ajax_nopriv_aichat_bedrock_chat', array($this, 'handle_chat_request'));
        
        // For backward compatibility
        add_action('wp_ajax_wpbedrock_chat', array($this, 'handle_chat_request'));
        add_action('wp_ajax_nopriv_wpbedrock_chat', array($this, 'handle_chat_request'));
        
        // Register shortcode
        add_shortcode('ai_chat_for_amazon_bedrock', array($this, 'render_chatbot'));
        // Keep the old shortcode for backward compatibility
        add_shortcode('bedrock_chat', array($this, 'render_chatbot'));
        
        // Register widget
        add_action('widgets_init', function() {
            register_widget('AICHAT_AMAZON_BEDROCK\\WP_Bedrock_Widget');
        });

        // Frontend scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'));
    }

    public function enqueue_public_assets() {
        // Only load if shortcode is present or widget is active
        if (is_active_widget(false, false, 'wp_bedrock_widget') || 
            has_shortcode(get_post_field('post_content', get_the_ID()), 'ai_chat_for_amazon_bedrock') ||
            has_shortcode(get_post_field('post_content', get_the_ID()), 'bedrock_chat')) {
            
            // Enqueue jQuery UI
            wp_enqueue_script('jquery-ui-core');
            wp_enqueue_script('jquery-ui-dialog');
            wp_enqueue_style('wp-jquery-ui-dialog');
            
            // Enqueue markdown-it and highlight.js
            wp_enqueue_script('markdown-it', AICHAT_BEDROCK_PLUGIN_URL . 'admin/js/markdown-it.min.js', array(), $this->version, true);
            wp_enqueue_script('highlight-js', AICHAT_BEDROCK_PLUGIN_URL . 'admin/js/highlight.min.js', array(), $this->version, true);
            wp_enqueue_style('github-css', AICHAT_BEDROCK_PLUGIN_URL . 'admin/css/github.min.css', array(), $this->version);
            
            // Enqueue plugin styles
            wp_enqueue_style('aichat-bedrock-public', AICHAT_BEDROCK_PLUGIN_URL . 'public/css/wp-bedrock-public.css', array(), $this->version);
            wp_enqueue_style('aichat-bedrock-chatbot', AICHAT_BEDROCK_PLUGIN_URL . 'admin/css/wp-bedrock-modern-chat.css', array(), $this->version);
            
            // Enqueue plugin scripts in correct order
            wp_enqueue_script('aichat-bedrock-api', AICHAT_BEDROCK_PLUGIN_URL . 'admin/js/wp-bedrock-api.js', array('jquery'), $this->version, true);
            wp_enqueue_script('aichat-bedrock-response-handler', AICHAT_BEDROCK_PLUGIN_URL . 'admin/js/wp-bedrock-response-handler.js', array('jquery', 'markdown-it', 'highlight-js'), $this->version, true);
            wp_enqueue_script('aichat-bedrock-chat-manager', AICHAT_BEDROCK_PLUGIN_URL . 'admin/js/wp-bedrock-chat-manager.js', array('jquery', 'aichat-bedrock-api'), $this->version, true);
            wp_enqueue_script('aichat-bedrock-chatbot', AICHAT_BEDROCK_PLUGIN_URL . 'admin/js/wp-bedrock-chatbot.js', array('jquery', 'jquery-ui-dialog', 'aichat-bedrock-chat-manager', 'aichat-bedrock-response-handler'), $this->version, true);
            wp_enqueue_script('aichat-bedrock-public', AICHAT_BEDROCK_PLUGIN_URL . 'public/js/wp-bedrock-public.js', array('aichat-bedrock-chatbot'), $this->version, true);
            
            // Localize script
            wp_localize_script('aichat-bedrock-chatbot', 'aichat_bedrock', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('aichat_bedrock_chat_nonce'),
                'initial_message' => get_option('aichat_bedrock_chat_initial_message', 'Hello! How can I help you today?'),
                'placeholder' => get_option('aichat_bedrock_chat_placeholder', 'Type your message here...'),
                'enable_stream' => get_option('aichat_bedrock_enable_stream', '1') === '1',
                'default_model' => get_option('aichat_bedrock_model_id', 'anthropic.claude-3-haiku-20240307-v1:0'),
                'default_temperature' => floatval(get_option('aichat_bedrock_temperature', '0.7')),
                'default_system_prompt' => get_option('aichat_bedrock_system_prompt', 'You are a helpful AI assistant.'),
                'plugin_url' => AICHAT_BEDROCK_PLUGIN_URL,
                'tools' => json_decode(file_get_contents(plugin_dir_path(__FILE__) . 'tools.json'), true)
            ));
            
            // For backward compatibility
            wp_localize_script('aichat-bedrock-chatbot', 'wpbedrock', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wpbedrock_chat_nonce'),
                'initial_message' => get_option('wpbedrock_chat_initial_message', 'Hello! How can I help you today?'),
                'placeholder' => get_option('wpbedrock_chat_placeholder', 'Type your message here...'),
                'enable_stream' => get_option('wpbedrock_enable_stream', '1') === '1',
                'default_model' => get_option('wpbedrock_model_id', 'anthropic.claude-3-haiku-20240307-v1:0'),
                'default_temperature' => floatval(get_option('wpbedrock_temperature', '0.7')),
                'default_system_prompt' => get_option('wpbedrock_system_prompt', 'You are a helpful AI assistant.'),
                'plugin_url' => WPBEDROCK_PLUGIN_URL,
                'tools' => json_decode(file_get_contents(plugin_dir_path(__FILE__) . 'tools.json'), true)
            ));
        }
    }

    public function render_chatbot($atts = array()) {
        // Parse attributes
        $atts = shortcode_atts(array(
            'height' => '500px',
            'width' => '100%'
        ), $atts);

        ob_start();
        include AICHAT_BEDROCK_PLUGIN_DIR . 'admin/partials/wp-bedrock-admin-chatbot.php';
        return ob_get_clean();
    }

    public function handle_chat_request() {
        try {
            // Check for both new and old nonces for backward compatibility
            if (isset($_POST['nonce'])) {
                $nonce = sanitize_text_field($_POST['nonce']);
                if (wp_verify_nonce($nonce, 'aichat_bedrock_chat_nonce') || wp_verify_nonce($nonce, 'wpbedrock_chat_nonce')) {
                    // Nonce is valid
                } else {
                    throw new \Exception('Invalid security token', 'SECURITY_ERROR');
                }
            } else {
                throw new \Exception('Security token missing', 'SECURITY_ERROR');
            }

            // Get request parameters with proper sanitization
            $request_body = isset($_POST['requestBody']) ? 
                json_decode(stripslashes(sanitize_textarea_field($_POST['requestBody'])), true) : 
                array();
                
            $stream = isset($_GET['stream']) && sanitize_text_field($_GET['stream']) === '1';

            if (empty($request_body)) {
                throw new \Exception('Invalid request body', 'INVALID_REQUEST');
            }

            // Initialize AWS client
            $aws_key = get_option('aichat_bedrock_aws_key', get_option('wpbedrock_aws_key'));
            $aws_secret = get_option('aichat_bedrock_aws_secret', get_option('wpbedrock_aws_secret'));
            $aws_region = get_option('aichat_bedrock_aws_region', get_option('wpbedrock_aws_region'));

            if (!$aws_key || !$aws_secret || !$aws_region) {
                throw new \Exception('AWS credentials not configured', 'CONFIG_ERROR');
            }

            $aws_client = new WP_Bedrock_AWS($aws_key, $aws_secret, $aws_region);

            // Get model ID from settings
            $model_id = get_option('aichat_bedrock_model_id', get_option('wpbedrock_model_id'));
            if (!$model_id) {
                throw new \Exception('Model ID not configured', 'CONFIG_ERROR');
            }

            if ($stream) {
                // Set headers for SSE
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('Connection: keep-alive');
                header('X-Accel-Buffering: no'); // Disable nginx buffering

                // Flush headers
                flush();

                // Stream response
                $aws_client->invoke_model($request_body, $model_id, true, function($event) {
                    echo "data: " . wp_json_encode($event) . "\n\n";
                    flush();
                });

                exit;
            } else {
                $response = $aws_client->invoke_model($request_body, $model_id, false);
                wp_send_json_success($response);
            }

        } catch (\Exception $e) {
            $error_code = $e->getCode() ?: 'UNKNOWN_ERROR';
            $error_response = [
                'error' => true,
                'code' => $error_code,
                'message' => $e->getMessage()
            ];

            if (isset($stream) && $stream) {
                echo "data: " . wp_json_encode($error_response) . "\n\n";
                exit;
            } else {
                wp_send_json_error($error_response);
            }
        }
    }

    /**
     * Execute the plugin
     */
    public function run() {
        // Set up admin hooks
        add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_styles'));
        add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_scripts'));
    }

    public static function activate() {
        // Activation code
    }

    public static function deactivate() {
        // Deactivation code
    }
}
