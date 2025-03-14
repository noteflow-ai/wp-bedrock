/**
 * Main chatbot initialization script
 */

// Debug mode check
const isDebugMode = () => typeof wpbedrock !== 'undefined' && wpbedrock.debug_mode === '1';

// Debug logging wrapper
const debugLog = (...args) => {
    if (isDebugMode()) {
        console.log('[AI Chat for Amazon Bedrock]', ...args);
    }
};

// Expose initialization function globally
window.initializeChat = async function(container) {
    // Dependencies are loaded through WordPress enqueue system
    const requiredDeps = {
        'jQuery': () => typeof jQuery !== 'undefined',
        'wpbedrock': () => typeof wpbedrock !== 'undefined',
        'markdownit': () => typeof window.markdownit !== 'undefined',
        'hljs': () => typeof window.hljs !== 'undefined',
        'jQuery UI Dialog': () => typeof jQuery !== 'undefined' && typeof jQuery.fn.dialog !== 'undefined',
        'BedrockAPI': () => typeof window.BedrockAPI !== 'undefined',
        'BedrockResponseHandler': () => typeof window.BedrockResponseHandler !== 'undefined',
        'BedrockChatManager': () => typeof window.BedrockChatManager !== 'undefined'
    };

    // Add timeout tracking
    window.wpBedrockInitAttempts = (window.wpBedrockInitAttempts || 0) + 1;
    const MAX_ATTEMPTS = 100; // 10 seconds total

    // Check if any dependencies are missing
    const missing = Object.entries(requiredDeps)
        .filter(([_, check]) => !check())
        .map(([name]) => name);

    if (missing.length > 0) {
        if (window.wpBedrockInitAttempts < MAX_ATTEMPTS) {
            debugLog(`Waiting for libraries (attempt ${window.wpBedrockInitAttempts}/${MAX_ATTEMPTS}):`, missing.join(', '));
            setTimeout(() => window.initializeChat(container), 100);
            return;
        } else {
            // Always log critical initialization failures
            console.error('[AI Chat for Amazon Bedrock] Failed to load required libraries:', missing.join(', '));
            container = container || document.querySelector('.chat-container');
            if (container) {
                container.innerHTML = `
                    <div class="error-message" style="padding: 20px; color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin: 10px;">
                        <h3 style="margin-top: 0;">Error: Chat Initialization Failed</h3>
                        <p>Failed to load required libraries: ${missing.join(', ')}</p>
                        <p>Please check your browser console for errors and try refreshing the page.</p>
                    </div>`;
            }
            return;
        }
    }

    // Reset attempts counter on successful load
    window.wpBedrockInitAttempts = 0;

    debugLog('All dependencies loaded, initializing chatbot...');
    const $ = jQuery;
    container = container || $('.chat-container');

    // Initialize chat manager with jQuery
    const chatManager = new BedrockChatManager(wpbedrock, {
        chatContainer: container,
        messagesContainer: container.find('#wpaicg-chat-messages'),
        messageInput: container.find('#wpaicg-chat-message'),
        sendButton: container.find('#wpaicg-send-message'),
        stopButton: container.find('#wpaicg-stop-message'),
        imageUpload: container.find('#wpaicg-image-upload'),
        imageTrigger: container.find('#wpaicg-image-trigger'),
        imagePreview: container.find('#wpaicg-image-preview'),
        previewImage: container.find('#wpaicg-preview-image'),
        removeImageButton: container.find('#wpaicg-remove-image'),
        clearChatButton: container.find('#clear-chat'),
        refreshChatButton: container.find('#refresh-chat'),
        exportChatButton: container.find('#export-chat'),
        shareChatButton: container.find('#share-chat'),
        fullscreenButton: container.find('#fullscreen-chat'),
        settingsTrigger: container.find('#wpaicg-settings-trigger'),
        promptTrigger: container.find('#wpaicg-prompt-trigger'),
        maskTrigger: container.find('#wpaicg-mask-trigger'),
        voiceTrigger: container.find('#wpaicg-voice-trigger'),
        gridTrigger: container.find('#wpaicg-grid-trigger'),
        layoutTrigger: container.find('#wpaicg-layout-trigger'),
        messageCountDisplay: container.find('.message-count')
    });

    // Initialize tools modal with jQuery
    const toolsModal = $('#tools-modal').dialog({
        autoOpen: false,
        modal: true,
        width: 600,
        dialogClass: 'tools-dialog',
        closeText: ''
    });

    // Set up event listeners with jQuery
    const setupEventListeners = ($, chatManager) => {
        // Tools modal
        $('#wpaicg-grid-trigger').on('click', () => toolsModal.dialog('open'));

        // Tool selection
        $('.tool-item').on('click', function() {
            const $this = $(this);
            const toolDefinition = JSON.parse($this.attr('data-tool-definition'));
            $this.toggleClass('selected');
            chatManager.toggleTool(toolDefinition);
        });

        // Message sending
        chatManager.elements.sendButton.on('click', () => chatManager.sendMessage());
        chatManager.elements.stopButton.on('click', () => {
            chatManager.responseHandler.stopStreaming();
            chatManager.setProcessingState(false);
        });

        // Image handling
        chatManager.elements.imageTrigger.on('click', () => 
            chatManager.elements.imageUpload.click()
        );

        chatManager.elements.imageUpload.on('change', function() {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    chatManager.elements.previewImage.attr('src', e.target.result);
                    chatManager.elements.imagePreview.show();
                };
                reader.readAsDataURL(this.files[0]);
            }
        });

        chatManager.elements.removeImageButton.on('click', () => {
            chatManager.elements.imagePreview.hide();
            chatManager.elements.imageUpload.val('');
        });

        // Text preview handling
        chatManager.elements.closePreviewButton = $('#wpaicg-close-preview');
        chatManager.elements.textPreview = $('#wpaicg-text-preview');
        chatManager.elements.previewContent = $('#wpaicg-preview-content');
        
        chatManager.elements.closePreviewButton.on('click', () => {
            chatManager.elements.textPreview.addClass('hidden');
            chatManager.elements.previewContent.html('');
        });
        
        // Function to show preview
        chatManager.showPreview = (content) => {
            chatManager.elements.previewContent.html(content);
            chatManager.elements.textPreview.removeClass('hidden');
        };

        // Chat management
        chatManager.elements.clearChatButton.on('click', () => chatManager.clearChat());
        chatManager.elements.refreshChatButton.on('click', () => location.reload());

        // Export chat
        chatManager.elements.exportChatButton.on('click', async () => {
            const chatContent = chatManager.messageHistory.map(msg => {
                const role = msg.role === 'assistant' ? 'AI' : 'User';
                const content = msg.content
                    .map(item => {
                        if (item.type === "text") return item.text;
                        if (item.type === "image") return "[Image]";
                        return "";
                    })
                    .filter(Boolean)
                    .join("\n");
                return `${role}: ${content}`;
            }).join('\n\n');

            try {
                await navigator.clipboard.writeText(chatContent);
                const button = chatManager.elements.exportChatButton;
                button.addClass('button-primary');
                setTimeout(() => button.removeClass('button-primary'), 1000);
            } catch (err) {
                // Always log clipboard errors as they indicate a browser API issue
                console.error('[AI Chat for Amazon Bedrock] Failed to copy chat:', err);
                alert('Failed to copy chat to clipboard');
            }
        });

        // Share chat
        chatManager.elements.shareChatButton.on('click', () => {
            const chatContent = encodeURIComponent(
                chatManager.messageHistory.map(msg => {
                    const role = msg.role === 'assistant' ? 'AI' : 'User';
                    const content = msg.content
                        .map(item => {
                            if (item.type === "text") return item.text;
                            if (item.image_url) return "[Image]";
                            return "";
                        })
                        .filter(Boolean)
                        .join("\n");
                    return `${role}: ${content}`;
                }).join('\n\n')
            );

            window.open(`https://twitter.com/intent/tweet?text=${chatContent}`, '_blank');
        });

        // Fullscreen handling
        let isFullscreen = false;
        chatManager.elements.fullscreenButton.on('click', () => {
            isFullscreen = !isFullscreen;
            chatManager.elements.chatContainer.toggleClass('fullscreen');
            chatManager.elements.fullscreenButton.find('.dashicons')
                .toggleClass('dashicons-fullscreen dashicons-fullscreen-exit');
            
            if (isFullscreen) {
                $('body').css('overflow', 'hidden');
            } else {
                $('body').css('overflow', '');
            }
        });

        // Layout handling
        chatManager.elements.layoutTrigger.on('click', () => {
            chatManager.elements.chatContainer.toggleClass('wide-layout');
            chatManager.elements.layoutTrigger.toggleClass('active');
        });

        // Message input handling
        chatManager.elements.messageInput
            .on('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    chatManager.sendMessage();
                }
            })
            .on('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });

        // Global keyboard shortcuts
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && isFullscreen) {
                chatManager.elements.fullscreenButton.click();
            }
        });
    };

    // Set up event listeners
    setupEventListeners($, chatManager);

    // Initialize chat
    chatManager.initialize();
}

// Initialize in admin context
jQuery(document).ready(function($) {
    if ($('body').hasClass('wp-admin')) {
        window.initializeChat();
    }
});
