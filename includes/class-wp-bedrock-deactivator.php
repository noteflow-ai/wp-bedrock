<?php
namespace WPBEDROCK;

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
