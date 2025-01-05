<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1>WP Bedrock Chatbot</h1>
    
    <div class="wpaicg-chat-widget">
        <div class="wpaicg-chat-widget-messages" id="wpaicg-chat-messages">
            <div class="wpaicg-chat-message wpaicg-ai-message">
                <?php echo esc_html(get_option('wpbedrock_chat_initial_message', 'Hello! How can I help you today?')); ?>
            </div>
        </div>
        <div class="wpaicg-chat-widget-input">
            <textarea 
                id="wpaicg-chat-message" 
                placeholder="<?php echo esc_attr(get_option('wpbedrock_chat_placeholder', 'Type your message here...')); ?>"
                rows="1"
            ></textarea>
            <button id="wpaicg-send-message" class="button button-primary">Send</button>
        </div>
    </div>

    <div class="wpaicg-chat-settings">
        <h2>Chat History</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>User Message</th>
                    <th>AI Response</th>
                </tr>
            </thead>
            <tbody id="wpaicg-chat-history">
                <!-- Chat history will be loaded here -->
            </tbody>
        </table>
    </div>
</div>
