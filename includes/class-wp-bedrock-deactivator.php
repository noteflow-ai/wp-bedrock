<?php
namespace AICHAT_AMAZON_BEDROCK;

/**
 * Fired during plugin deactivation
 */
class WP_Bedrock_Deactivator {

    /**
     * Plugin deactivation tasks
     */
    public static function deactivate() {
        // Nothing to do on deactivation for now
        // Tables will be cleaned up during uninstall if needed
    }
}
