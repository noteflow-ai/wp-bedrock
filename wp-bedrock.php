<?php
/**
 * Backward compatibility file for AI Chat for Amazon Bedrock
 * 
 * This file is kept for backward compatibility and redirects to the new main plugin file.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Include the new main plugin file
require_once plugin_dir_path(__FILE__) . 'ai-chat-for-amazon-bedrock.php';
