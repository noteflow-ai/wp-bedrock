<?php
namespace WPBEDROCK;

class WP_Bedrock {
    private $tools;
    private $plugin_name;
    private $version;
    private $admin;

    public function __construct() {
        $this->plugin_name = 'wp-bedrock';
        $this->version = WPBEDROCK_VERSION;
        $this->load_dependencies();
        $this->setup_hooks();
    }

    private function load_dependencies() {
      
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-wp-bedrock-admin.php';
        
       
        $this->admin = new WP_Bedrock_Admin($this->plugin_name, $this->version);
    }

    private function setup_hooks() {
        add_action('wp_ajax_wpbedrock_chat', array($this, 'handle_chat_request'));
        add_action('wp_ajax_nopriv_wpbedrock_chat', array($this, 'handle_chat_request'));
        add_action('wp_ajax_wpbedrock_tool', array($this, 'handle_tool_request'));
        add_action('wp_ajax_nopriv_wpbedrock_tool', array($this, 'handle_tool_request'));
    }

    public function handle_chat_request() {
        check_ajax_referer('wpbedrock_chat_nonce', 'nonce');

        $message = sanitize_text_field($_POST['message'] ?? '');
        $image = sanitize_text_field($_POST['image'] ?? '');
        $history = json_decode(stripslashes($_POST['history'] ?? '[]'), true);

        if (empty($message)) {
            wp_send_json_error('Message is required');
        }

        try {
            // Your existing chat handling code here
            // ...

            // Add tools to the chat context
            $tools = $this->tools->get_tools();
            // Add tools to your chat API call
            // ...

        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function handle_tool_request() {
        check_ajax_referer('wpbedrock_chat_nonce', 'nonce');

        $tool_name = sanitize_text_field($_POST['tool'] ?? '');
        $parameters = json_decode(stripslashes($_POST['parameters'] ?? '{}'), true);

        if (empty($tool_name)) {
            wp_send_json_error('Tool name is required');
        }

        try {
            $result = $this->tools->execute_tool($tool_name, $parameters);
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function get_tools() {
        return $this->tools->get_tools();
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
