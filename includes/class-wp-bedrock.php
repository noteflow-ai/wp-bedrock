<?php
namespace WPBEDROCK;

class WP_Bedrock {
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
    }

    public function handle_chat_request() {
        try {
            check_ajax_referer('wpbedrock_chat_nonce', 'nonce');

            // Get request parameters
            $request_body = json_decode(stripslashes($_POST['requestBody'] ?? '{}'), true);
            $stream = isset($_GET['stream']) && $_GET['stream'] === '1';

            if (empty($request_body)) {
                throw new \Exception('Invalid request body', 'INVALID_REQUEST');
            }

            // Initialize AWS client
            $aws_key = get_option('wpbedrock_aws_key');
            $aws_secret = get_option('wpbedrock_aws_secret');
            $aws_region = get_option('wpbedrock_aws_region');

            if (!$aws_key || !$aws_secret || !$aws_region) {
                throw new \Exception('AWS credentials not configured', 'CONFIG_ERROR');
            }

            $aws_client = new WP_Bedrock_AWS($aws_key, $aws_secret, $aws_region);

            // Get model ID from settings
            $model_id = get_option('wpbedrock_model_id');
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
                    echo "data: " . json_encode($event) . "\n\n";
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
                echo "data: " . json_encode($error_response) . "\n\n";
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
