<?php 
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1><?php esc_html_e('AI Chat', 'wp-bedrock'); ?></h1>
    
    <div class="wp-bedrock-settings-container">
        <div class="settings-section">
            <div class="chat-container">
                <div class="chat-main">
                    <div class="chat-header">
                        <div>
                            <span class="chat-title"><?php esc_html_e('New Conversation', 'wp-bedrock'); ?></span>
                            <span class="message-count"><?php esc_html_e('0 messages', 'wp-bedrock'); ?></span>
                        </div>
                        <div class="chat-actions">
                            <button id="refresh-chat" class="button" title="<?php esc_attr_e('Refresh', 'wp-bedrock'); ?>">
                                <span class="dashicons dashicons-update"></span>
                            </button>
                            <button id="export-chat" class="button" title="<?php esc_attr_e('Copy Chat', 'wp-bedrock'); ?>">
                                <span class="dashicons dashicons-clipboard"></span>
                            </button>
                            <button id="share-chat" class="button" title="<?php esc_attr_e('Share', 'wp-bedrock'); ?>">
                                <span class="dashicons dashicons-share"></span>
                            </button>
                            <button id="fullscreen-chat" class="button" title="<?php esc_attr_e('Full Screen', 'wp-bedrock'); ?>">
                                <span class="dashicons dashicons-fullscreen"></span>
                            </button>
                            <button id="clear-chat" class="button" title="<?php esc_attr_e('Clear Chat', 'wp-bedrock'); ?>">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </div>
                    </div>

                    <div id="wpaicg-chat-messages" class="chat-messages">
                        <!-- Example AI Message Structure -->
                        <div class="chat-message ai hidden">
                            <div class="avatar">
                                <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%23666' d='M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z'/%3E%3C/svg%3E" alt="AI">
                            </div>
                            <div class="message-content"></div>
                            <div class="message-timestamp"></div>
                        </div>
                        <!-- Contextual Prompt -->
                        <div class="contextual-prompt hidden">Contextual Prompt</div>
                        <!-- Example User Message Structure -->
                        <div class="chat-message user hidden">
                            <div class="message-content"></div>
                            <div class="message-timestamp"></div>
                        </div>
                    </div>

                    <div class="chat-input-container">
                        <div class="chat-input-area">
                            <div class="action-buttons">
                                <button type="button" id="wpaicg-settings-trigger" class="button" title="<?php esc_attr_e('Settings', 'wp-bedrock'); ?>">
                                    <span class="dashicons dashicons-admin-generic"></span>
                                </button>
                                <button type="button" id="wpaicg-prompt-trigger" class="button" title="<?php esc_attr_e('Prompts', 'wp-bedrock'); ?>">
                                    <span class="dashicons dashicons-book"></span>
                                </button>
                                <button type="button" id="wpaicg-mask-trigger" class="button" title="<?php esc_attr_e('Masks', 'wp-bedrock'); ?>">
                                    <span class="dashicons dashicons-admin-users"></span>
                                </button>
                                <button type="button" id="wpaicg-image-trigger" class="button" title="<?php esc_attr_e('Image', 'wp-bedrock'); ?>">
                                    <span class="dashicons dashicons-format-image"></span>
                                </button>
                                <button type="button" id="wpaicg-voice-trigger" class="button" title="<?php esc_attr_e('Voice', 'wp-bedrock'); ?>">
                                    <span class="dashicons dashicons-microphone"></span>
                                </button>
                                <button type="button" id="wpaicg-grid-trigger" class="button" title="<?php esc_attr_e('Tools', 'wp-bedrock'); ?>">
                                    <span class="dashicons dashicons-grid-view"></span>
                                </button>
                <!-- Tools Modal -->
                <div id="tools-modal" title="<?php esc_attr_e('Available Tools', 'wp-bedrock'); ?>" class="hidden">
                                    <div class="tools-container">
                                        <?php
                                        try {
                                            $tools_json_path = plugin_dir_path(dirname(__FILE__)) . '../includes/tools.json';
                                            if (!file_exists($tools_json_path)) {
                                                error_log('[AI Chat for Amazon Bedrock] tools.json not found at: ' . $tools_json_path);
                                                throw new Exception('Tools configuration file not found');
                                            }
                                            
                                            $tools_json = file_get_contents($tools_json_path);
                                            if ($tools_json === false) {
                                                error_log('[AI Chat for Amazon Bedrock] Failed to read tools.json');
                                                throw new Exception('Failed to read tools configuration');
                                            }
                                            
                                            $tools = json_decode($tools_json, true);
                                            if (json_last_error() !== JSON_ERROR_NONE) {
                                                error_log('[AI Chat for Amazon Bedrock] Invalid JSON in tools.json: ' . json_last_error_msg());
                                                throw new Exception('Invalid tools configuration format');
                                            }
                                            
                                            if ($tools && isset($tools['tools'])) {
                                                foreach ($tools['tools'] as $tool) {
                                                    if (isset($tool['info'])) {
                                                        $name = $tool['info']['title'];
                                                        $description = $tool['info']['description'];
                                                        // Convert snake_case to Title Case for display
                                                        $display_name = ucwords(str_replace('_', ' ', $name));
                                                        $tool_json = json_encode($tool);
                                                        echo '<div class="tool-item" data-tool="' . esc_attr($name) . '" data-tool-definition=\'' . esc_attr($tool_json) . '\' title="' . esc_attr($description) . '">
                                                            <div class="tool-header">
                                                                <span class="checkbox"></span>
                                                                <span class="tool-name">' . esc_html($display_name) . '</span>
                                                            </div>
                                                            <div class="tool-description">' . esc_html($description) . '</div>
                                                        </div>';
                                                    }
                                                }
                                            }
                                        } catch (Exception $e) {
                                            // error_log('[AI Chat for Amazon Bedrock] Error loading tools: ' . $e->getMessage());
                                            // echo '<!-- Tools loading error: ' . esc_html($e->getMessage()) . ' -->';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>

                            <div class="input-container">
                                <div class="input-wrapper">
                                    <button type="button" class="emoji-trigger" title="<?php esc_attr_e('Choose emoji', 'wp-bedrock'); ?>">
                                        <span class="dashicons dashicons-smiley"></span>
                                    </button>
                                    <textarea 
                                        id="wpaicg-chat-message" 
                                        placeholder="<?php esc_attr_e('Enter to send, Shift + Enter to wrap, / to search prompts, : to use commands', 'wp-bedrock'); ?>"
                                        rows="1"
                                    ></textarea>
                                    <button type="button" id="wpaicg-send-message">
                                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                                    </button>
                                    <button type="button" id="wpaicg-stop-message" class="hidden">
                                        <span class="dashicons dashicons-controls-pause"></span>
                                    </button>
                                </div>
                            </div>

                            <div id="wpaicg-image-preview" class="image-preview hidden">
                                <img id="wpaicg-preview-image" src="" alt="Preview">
                                <button type="button" id="wpaicg-remove-image" class="button">
                                    <span class="dashicons dashicons-no"></span>
                                </button>
                            </div>
                            
                            <div id="wpaicg-text-preview" class="text-preview hidden">
                                <div class="preview-header">
                                    <span>Preview</span>
                                    <button type="button" id="wpaicg-close-preview" class="button">
                                        <span class="dashicons dashicons-no"></span>
                                    </button>
                                </div>
                                <div id="wpaicg-preview-content" class="preview-content"></div>
                            </div>
                            
                            <input type="file" id="wpaicg-image-upload" accept="image/*" class="hidden">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
