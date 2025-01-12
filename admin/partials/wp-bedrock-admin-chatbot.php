<?php 
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1>Bedrock AI Chatbot</h1>
    
    <div class="wp-bedrock-settings-container">
        <div class="settings-section">
            <div class="chat-container">
                <div class="chat-main">
                    <div class="chat-header">
                        <div>
                            <span class="chat-title">New Conversation</span>
                            <span class="message-count">0 messages</span>
                        </div>
                        <div class="chat-actions">
                            <button id="refresh-chat" class="button" title="Refresh">
                                <span class="dashicons dashicons-update"></span>
                            </button>
                            <button id="export-chat" class="button" title="Copy Chat">
                                <span class="dashicons dashicons-clipboard"></span>
                            </button>
                            <button id="share-chat" class="button" title="Share">
                                <span class="dashicons dashicons-share"></span>
                            </button>
                            <button id="fullscreen-chat" class="button" title="Full Screen">
                                <span class="dashicons dashicons-fullscreen"></span>
                            </button>
                            <button id="clear-chat" class="button" title="Clear Chat">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </div>
                    </div>

                    <div id="wpaicg-chat-messages" class="chat-messages">
                        <!-- Example AI Message Structure -->
                        <div class="chat-message ai" style="display: none;">
                            <div class="avatar">
                                <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%23666' d='M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z'/%3E%3C/svg%3E" alt="AI">
                            </div>
                            <div class="message-content"></div>
                            <div class="message-timestamp"></div>
                        </div>
                        <!-- Example User Message Structure -->
                        <div class="chat-message user" style="display: none;">
                            <div class="message-content"></div>
                            <div class="message-timestamp"></div>
                        </div>
                    </div>

                    <div class="chat-input-container">
                        <div class="chat-input-area">
                            <div class="action-buttons">
                                <button type="button" id="wpaicg-settings-trigger" class="button" title="Settings">
                                    <span class="dashicons dashicons-admin-generic"></span>
                                </button>
                                <button type="button" id="wpaicg-prompt-trigger" class="button" title="Prompts">
                                    <span class="dashicons dashicons-book"></span>
                                </button>
                                <button type="button" id="wpaicg-mask-trigger" class="button" title="Masks">
                                    <span class="dashicons dashicons-admin-users"></span>
                                </button>
                                <button type="button" id="wpaicg-image-trigger" class="button" title="Image">
                                    <span class="dashicons dashicons-format-image"></span>
                                </button>
                                <button type="button" id="wpaicg-voice-trigger" class="button" title="Voice">
                                    <span class="dashicons dashicons-microphone"></span>
                                </button>
                                <button type="button" id="wpaicg-grid-trigger" class="button" title="Tools">
                                    <span class="dashicons dashicons-grid-view"></span>
                                </button>
                                <!-- Tools Modal -->
                                <div id="tools-modal" title="Available Tools" style="display:none;">
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
                                                    if (isset($tool['function'])) {
                                                        $name = $tool['function']['name'];
                                                        $description = $tool['function']['description'];
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
                                            error_log('[AI Chat for Amazon Bedrock] Error loading tools: ' . $e->getMessage());
                                            echo '<!-- Tools loading error: ' . esc_html($e->getMessage()) . ' -->';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>

                            <div class="input-container">
                                <div class="input-wrapper">
                                    <button type="button" class="emoji-trigger" title="Choose emoji">
                                        <span class="dashicons dashicons-smiley"></span>
                                    </button>
                                    <textarea 
                                        id="wpaicg-chat-message" 
                                        placeholder="Enter to send, Shift + Enter to wrap, / to search prompts, : to use commands"
                                        rows="1"
                                    ></textarea>
                                    <button type="button" id="wpaicg-send-message">
                                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                                    </button>
                                    <button type="button" id="wpaicg-stop-message" style="display:none;">
                                        <span class="dashicons dashicons-controls-pause"></span>
                                    </button>
                                </div>
                            </div>

                            <div id="wpaicg-image-preview" class="image-preview" style="display: none;">
                                <img id="wpaicg-preview-image" src="" alt="Preview">
                                <button type="button" id="wpaicg-remove-image" class="button">
                                    <span class="dashicons dashicons-no"></span>
                                </button>
                            </div>
                            
                            <input type="file" id="wpaicg-image-upload" accept="image/*" style="display: none;">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.wp-bedrock-settings-container {
    max-width: 1200px;
    margin: 20px auto;
}

.settings-section {
    background: #fff;
    border: 1px solid #ebedf0;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

/* Chat Interface Styles */
.chat-container {
    display: flex;
    height: calc(100vh - 180px);
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
    border: 1px solid #ebedf0;
}

.chat-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    height: 100%;
    background: #fff;
}

.chat-header {
    padding: 15px 20px;
    border-bottom: 1px solid #ebedf0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fff;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.chat-title {
    font-size: 14px;
    font-weight: 600;
    color: #1c1e21;
}

.chat-actions {
    display: flex;
    gap: 12px;
}

.chat-actions .button {
    padding: 8px;
    border-radius: 50%;
    background: transparent;
    border: none;
    color: #65676b;
    cursor: pointer;
    transition: all 0.2s;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.chat-actions .button:hover {
    background: #f2f3f5;
    color: #1877f2;
}

.chat-actions .button .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
}

/* Messages Area */
.chat-messages {
    flex: 1;
    padding: 20px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 16px;
    background: #ffffff;
}

.chat-message {
    display: flex;
    flex-direction: column;
    max-width: 80%;
    position: relative;
}

.chat-message.ai {
    align-self: flex-start;
    margin-left: 40px;
}

.chat-message.user {
    align-self: flex-end;
}

.message-content {
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 14px;
    line-height: 1.5;
    position: relative;
}

.ai .message-content {
    background: #f0f2f5;
    border-top-left-radius: 4px;
}

.user .message-content {
    background: #e7f8ff;
    color: #000000;
    border-top-right-radius: 4px;
}

.ai .avatar {
    width: 32px;
    height: 32px;
    position: absolute;
    left: -40px;
    top: 0;
    border-radius: 50%;
    background: #e1e9ef;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ai .avatar img {
    width: 20px;
    height: 20px;
}

.message-timestamp {
    font-size: 11px;
    color: #65676b;
    margin-top: 4px;
    opacity: 0.8;
}

.message-actions {
    display: flex;
    gap: 8px;
    margin-top: 4px;
    opacity: 0;
    transition: opacity 0.2s;
}

.chat-message:hover .message-actions {
    opacity: 1;
}

/* Input Area */
.chat-input-container {
    padding: 16px;
    border-top: 1px solid #ebedf0;
    background: #fff;
}

.chat-input-area {
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-width: 900px;
    margin: 0 auto;
}

.input-wrapper {
    position: relative;
    width: 100%;
    background: #f0f2f5;
    border-radius: 20px;
    padding: 8px 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

#wpaicg-chat-message {
    flex: 1;
    padding: 8px 0;
    border: none;
    background: transparent;
    resize: none;
    min-height: 20px;
    max-height: 200px;
    font-size: 14px;
    line-height: 1.5;
    outline: none;
}

#wpaicg-chat-message:focus {
    outline: none;
    box-shadow: none;
}

.input-wrapper:focus-within {
    outline: none;
    box-shadow: none;
}

.emoji-trigger {
    padding: 4px;
    cursor: pointer;
    color: #65676b;
    border: none;
    background: none;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.2s;
}

.emoji-trigger:hover {
    background: #e4e6eb;
}

#wpaicg-send-message {
    padding: 6px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: #0084ff;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background-color 0.2s;
}

#wpaicg-send-message:hover {
    background: #0073e6;
}

.action-buttons {
    display: flex;
    gap: 12px;
    position: relative;
    padding: 0 8px;
}

.action-buttons .button {
    padding: 8px;
    border-radius: 50%;
    background: transparent;
    border: none;
    color: #65676b;
    cursor: pointer;
    transition: all 0.2s;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.action-buttons .button:hover {
    background: #f2f3f5;
    color: #1877f2;
}

.action-buttons .button .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
}

/* Tools Modal */
#tools-modal {
    max-width: 600px;
}

.tools-container {
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-height: 400px;
    overflow-y: auto;
    padding: 4px;
}

.tool-item {
    padding: 12px;
    border: 1px solid #ebedf0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.tool-item:hover {
    background: #f8f9fa;
    border-color: #1877f2;
}

.tool-item.selected {
    background: #e7f8ff;
    border-color: #1877f2;
}

.tool-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}

.tool-header .checkbox {
    width: 16px;
    height: 16px;
    border: 2px solid #65676b;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.tool-item.selected .checkbox {
    background: #1877f2;
    border-color: #1877f2;
}

.tool-item.selected .checkbox:after {
    content: '';
    width: 8px;
    height: 8px;
    background: white;
    border-radius: 2px;
}

.tool-name {
    font-weight: 600;
    color: #1c1e21;
}

.tool-description {
    font-size: 13px;
    color: #65676b;
    margin-left: 24px;
}

/* Override jQuery UI Dialog styles */
.ui-dialog.tools-dialog {
    padding: 0;
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
}

.ui-dialog.tools-dialog .ui-dialog-titlebar {
    background: #fff;
    border: none;
    border-bottom: 1px solid #ebedf0;
    padding: 16px 20px;
    border-radius: 12px 12px 0 0;
}

.ui-dialog.tools-dialog .ui-dialog-title {
    font-size: 16px;
    font-weight: 600;
    color: #1c1e21;
}

.ui-dialog.tools-dialog .ui-dialog-titlebar-close {
    border: none;
    background: transparent;
    color: #65676b;
    right: 12px;
}

.ui-dialog.tools-dialog .ui-dialog-titlebar-close:hover {
    background: #f2f3f5;
    border-radius: 50%;
}

.ui-dialog.tools-dialog .ui-dialog-content {
    padding: 16px 20px;
}

.image-preview {
    position: relative;
    max-width: 200px;
    margin-top: 10px;
}

.image-preview img {
    width: 100%;
    height: auto;
    border-radius: 8px;
}

#wpaicg-remove-image {
    position: absolute;
    top: -8px;
    right: -8px;
    padding: 0;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: white;
    border: 1px solid #ebedf0;
    cursor: pointer;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

/* Loading Animation */
.typing-indicator {
    display: flex;
    gap: 4px;
    padding: 8px 12px;
    background: #fff;
    border-radius: 12px;
    width: fit-content;
}

.typing-indicator span {
    width: 6px;
    height: 6px;
    background: #65676b;
    border-radius: 50%;
    animation: typing 1s infinite ease-in-out;
}

.typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }

@keyframes typing {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-4px); }
}

/* Code Block Styles */
.code-block {
    position: relative;
    margin: 1em 0;
}

.code-block pre {
    background: #282c34;
    color: #abb2bf;
    padding: 16px;
    border-radius: 8px;
    overflow-x: auto;
    margin: 0;
}

.code-block code {
    font-family: 'Fira Code', monospace;
    font-size: 14px;
}

.copy-code {
    position: absolute;
    top: 8px;
    right: 8px;
    padding: 4px 8px;
    background: rgba(255, 255, 255, 0.1);
    border: none;
    border-radius: 4px;
    color: #abb2bf;
    font-size: 12px;
    cursor: pointer;
    transition: background-color 0.2s;
}

.copy-code:hover {
    background: rgba(255, 255, 255, 0.2);
}

/* Markdown Styles */
.message-content {
    overflow-wrap: break-word;
}

.message-content p {
    margin: 0 0 16px;
}

.message-content p:last-child {
    margin-bottom: 0;
}

.message-content ul, 
.message-content ol {
    margin: 8px 0;
    padding-left: 24px;
}

.message-content blockquote {
    border-left: 4px solid #ebedf0;
    margin: 8px 0;
    padding-left: 16px;
    color: #65676b;
}

.message-content img {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
}

/* Dialog Styles */
.ui-dialog {
    padding: 0;
    border: none;
    border-radius: 8px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
}

.ui-dialog .ui-dialog-titlebar {
    border: none;
    background: #fff;
    padding: 16px;
    border-bottom: 1px solid #ebedf0;
}

.ui-dialog .ui-dialog-content {
    padding: 16px;
}

.prompt-item,
.mask-item {
    padding: 12px;
    border: 1px solid #ebedf0;
    border-radius: 8px;
    margin-bottom: 8px;
}

.prompt-item h4,
.mask-item h4 {
    margin: 0 0 8px;
    color: #1c1e21;
}

.prompt-item p,
.mask-item p {
    margin: 0 0 8px;
    color: #65676b;
}

.prompt-item button,
.mask-item button {
    float: right;
}

/* Fullscreen Mode */
.chat-container.fullscreen {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 99999;
    height: 100vh;
    margin: 0;
    border-radius: 0;
}
</style>
