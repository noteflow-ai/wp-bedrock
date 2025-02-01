<?php
namespace WPBEDROCK;

/**
 * Fired during plugin activation
 */
class WP_Bedrock_Activator {

    /**
     * Create necessary database tables and initialize plugin settings
     */
    public static function activate() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        
        // Settings table
        $table_name = $wpdb->prefix . 'wpbedrock';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            aws_access_key varchar(255) DEFAULT '',
            aws_secret_key varchar(255) DEFAULT '',
            aws_region varchar(50) DEFAULT 'us-east-1',
            model_id varchar(100) DEFAULT 'us.anthropic.claude-3-haiku-20240307-v1',
            temperature float DEFAULT 0.7,
            max_tokens int DEFAULT 1000,
            top_p float DEFAULT 1,
            frequency_penalty float DEFAULT 0,
            presence_penalty float DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Conversations table
        $table_name = $wpdb->prefix . 'wpbedrock_conversations';
        $sql .= "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            title varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Messages table
        $table_name = $wpdb->prefix . 'wpbedrock_messages';
        $sql .= "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            conversation_id mediumint(9) NOT NULL,
            role varchar(50) NOT NULL,
            content text NOT NULL,
            tokens int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Start output buffering to catch any potential output
        ob_start();
        try {
            \dbDelta($sql);

            // Insert default settings if not exists
            $table_name = $wpdb->prefix . 'wpbedrock';
            $settings = $wpdb->get_row("SELECT * FROM $table_name WHERE name = 'wpbedrock_settings'");
            
            if (!$settings) {
                try {
                    $wpdb->insert(
                        $table_name,
                        array(
                            'name' => 'wpbedrock_settings',
                            'aws_region' => 'us-east-1',
                            'model_id' => 'us.anthropic.claude-3-haiku-20240307-v1',
                            'temperature' => 0.7,
                            'max_tokens' => 1000,
                            'top_p' => 1,
                            'frequency_penalty' => 0,
                            'presence_penalty' => 0
                        )
                    );
                    if ($wpdb->last_error) {
                        throw new \Exception($wpdb->last_error);
                    }
                } catch (\Exception $e) {
                    error_log('AI Chat for Amazon Bedrock activation error: ' . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            error_log('AI Chat for Amazon Bedrock activation error: ' . $e->getMessage());
        } finally {
            ob_end_clean();
        }
    }
}
