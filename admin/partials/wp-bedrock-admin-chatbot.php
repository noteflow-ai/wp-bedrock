<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap wp-bedrock-chat">
    <div class="chat-container">
        <!-- Main Chat Area -->
        <div class="chat-main">
            <div class="chat-header">
                <div class="chat-title">Chat Session</div>
                <div class="chat-actions">
                    <button id="export-chat" class="button" title="Export Chat">
                        <span class="dashicons dashicons-download"></span>
                    </button>
                    <button id="clear-chat" class="button" title="Clear Chat">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
            </div>

            <div id="wpaicg-chat-messages" class="chat-messages">
                <div class="chat-message ai">
                    <div class="message-content">
                        <?php echo esc_html(get_option('wpbedrock_chat_initial_message', 'Hello! How can I help you today?')); ?>
                    </div>
                </div>
            </div>

            <div class="chat-input-container">
                <div class="chat-input-area">
                    <div class="input-wrapper">
                        <textarea 
                            id="wpaicg-chat-message" 
                            placeholder="<?php echo esc_attr(get_option('wpbedrock_chat_placeholder', 'Type your message here...')); ?>"
                            rows="1"
                        ></textarea>
                    </div>

                    <div class="chat-input-actions">
                        <div class="action-buttons">
                            <button type="button" id="wpaicg-image-trigger" class="button" title="Upload Image">
                                <span class="dashicons dashicons-format-image"></span>
                            </button>
                            <button type="button" id="wpaicg-prompt-trigger" class="button" title="Prompt Library">
                                <span class="dashicons dashicons-book"></span>
                            </button>
                            <button type="button" id="wpaicg-mask-trigger" class="button" title="Conversation Masks">
                                <span class="dashicons dashicons-admin-users"></span>
                            </button>
                            <a href="<?php echo admin_url('admin.php?page=wp-bedrock_settings'); ?>" class="button" title="Chat Settings">
                                <span class="dashicons dashicons-admin-generic"></span>
                            </a>
                        </div>

                        <div class="send-buttons">
                            <button type="button" id="wpaicg-send-message" class="button button-primary">
                                <span class="dashicons dashicons-send"></span>
                            </button>
                            <button type="button" id="wpaicg-stop-message" class="button" style="display:none;">
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

<style>
/* Modern Chat Interface Styles */
.wp-bedrock-chat {
    --chat-bg: #ffffff;
    --message-bg: #f0f2f5;
    --ai-message-bg: #ffffff;
    --user-message-bg: #dcf8c6;
    --border-color: #e0e0e0;
    --text-color: #333333;
    --secondary-text: #666666;
    --button-hover: #f5f5f5;
}

.chat-container {
    display: flex;
    height: calc(100vh - 120px);
    background: var(--chat-bg);
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* Main Chat Area Styles */
.chat-main {
    flex-grow: 1;
    display: flex;
    flex-direction: column;
    background: var(--chat-bg);
}

.chat-header {
    padding: 15px 20px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chat-title {
    font-size: 16px;
    font-weight: 600;
}

.chat-actions {
    display: flex;
    gap: 10px;
}

/* Messages Area */
.chat-messages {
    flex-grow: 1;
    padding: 20px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.chat-message {
    display: flex;
    flex-direction: column;
    max-width: 85%;
}

.chat-message.ai {
    align-self: flex-start;
}

.chat-message.user {
    align-self: flex-end;
}

.message-content {
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 14px;
    line-height: 1.5;
}

.ai .message-content {
    background: var(--ai-message-bg);
    border: 1px solid var(--border-color);
}

.user .message-content {
    background: var(--user-message-bg);
    color: #000000;
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
    padding: 20px;
    border-top: 1px solid var(--border-color);
}

.chat-input-area {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 800px;
    margin: 0 auto;
}

.input-wrapper {
    position: relative;
    width: 100%;
}

#wpaicg-chat-message {
    width: 100%;
    padding: 12px;
    padding-right: 50px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    resize: none;
    min-height: 24px;
    max-height: 200px;
    font-size: 14px;
    line-height: 1.5;
}

.chat-input-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
}

.action-buttons {
    display: flex;
    gap: 8px;
}

.action-buttons .button {
    padding: 8px;
    border-radius: 8px;
    background: transparent;
    border: none;
    color: var(--secondary-text);
    cursor: pointer;
    transition: background-color 0.2s;
}

.action-buttons .button:hover {
    background: var(--button-hover);
}

.send-buttons {
    display: flex;
    gap: 8px;
}

.send-buttons .button {
    padding: 8px 16px;
    border-radius: 8px;
}

.send-buttons .button-primary {
    background: #2271b1;
    color: white;
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
    border: 1px solid var(--border-color);
    cursor: pointer;
}

/* Loading Animation */
.typing-indicator {
    display: flex;
    gap: 4px;
    padding: 8px 12px;
    background: var(--ai-message-bg);
    border-radius: 12px;
    width: fit-content;
}

.typing-indicator span {
    width: 8px;
    height: 8px;
    background: #90909090;
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
    border-left: 4px solid var(--border-color);
    margin: 8px 0;
    padding-left: 16px;
    color: var(--secondary-text);
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
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.ui-dialog .ui-dialog-titlebar {
    border: none;
    background: #fff;
    padding: 16px;
    border-bottom: 1px solid var(--border-color);
}

.ui-dialog .ui-dialog-content {
    padding: 16px;
}

.prompt-item,
.mask-item {
    padding: 12px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    margin-bottom: 8px;
}

.prompt-item h4,
.mask-item h4 {
    margin: 0 0 8px;
}

.prompt-item p,
.mask-item p {
    margin: 0 0 8px;
    color: var(--secondary-text);
}

.prompt-item button,
.mask-item button {
    float: right;
}
</style>
