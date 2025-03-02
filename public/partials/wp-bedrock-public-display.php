<?php
/**
 * Public-facing view for the plugin
 *
 * @since      1.0.0
 * @package    WP_Bedrock
 * @subpackage WP_Bedrock/public/partials
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

?><div class="wp-bedrock-chat-container">
    <div class="wp-bedrock-chat-messages" id="wp-bedrock-chat-messages"></div>
    <div class="wp-bedrock-chat-input">
        <textarea id="wp-bedrock-chat-input" placeholder="<?php echo esc_attr(get_option('wpbedrock_chat_placeholder', 'Type your message...')); ?>"></textarea>
        <button id="wp-bedrock-chat-submit" class="button button-primary">Send</button>
    </div>
</div>
