/**
 * Chat manager for handling chat UI and interactions
 */
class BedrockChatManager {
    constructor(config, elements) {
        // Store jQuery reference
        this.$ = jQuery;

        // Store config
        this.config = config;

        // Store UI elements
        this.elements = elements;

        // Initialize state
        this.messageHistory = [];
        this.selectedTools = [];
        this.isProcessing = false;
        this.currentEventSource = null;

        // Initialize handlers
        this.api = new BedrockAPI();
        this.toolHandler = new BedrockToolHandler();
        this.responseHandler = new BedrockResponseHandler();

        // Set up response handler callbacks
        this.responseHandler.setCallbacks({
            onContent: (text) => this.handleStreamContent(text),
            onError: (error) => this.handleError(error),
            onComplete: () => this.handleStreamComplete()
        });

        // Initialize markdown parser
        this.md = window.markdownit({
            html: true,
            linkify: true,
            typographer: true,
            highlight: (code, lang) => {
                if (lang && window.hljs.getLanguage(lang)) {
                    try {
                        return window.hljs.highlight(code, { language: lang }).value;
                    } catch (error) {
                        console.error('[BedrockChatManager] Code highlighting error:', error);
                    }
                }
                return window.hljs.highlightAuto(code).value;
            }
        });
    }

    // Initialize chat
    initialize() {
        // Create initial message
        if (this.config.initial_message) {
            const message = this.createMessageElement('assistant', this.config.initial_message);
            this.elements.messagesContainer.append(message);
            this.messageHistory.push({
                role: 'assistant',
                content: [{ type: 'text', text: this.config.initial_message }]
            });
        }

        // Set placeholder
        if (this.config.placeholder) {
            this.elements.messageInput.attr('placeholder', this.config.placeholder);
        }

        // Update message count
        this.updateMessageCount();
    }

    // Create message element
    createMessageElement(role, content, isError = false) {
        const messageClass = role === 'assistant' ? 'assistant-message' : 'user-message';
        const $ = this.$;

        const messageElement = $('<div>')
            .addClass(`chat-message ${messageClass}`)
            .append(
                $('<div>')
                    .addClass('message-content')
                    .html(isError ? this.formatErrorMessage(content) : this.formatMessage(content))
            );

        if (role === 'assistant' && !isError) {
            messageElement.append(
                $('<div>')
                    .addClass('message-actions')
                    .append(
                        $('<button>')
                            .addClass('copy-message')
                            .html('<span class="dashicons dashicons-clipboard"></span>')
                            .attr('title', 'Copy to clipboard')
                            .on('click', () => this.copyToClipboard(content))
                    )
            );
        }

        return messageElement;
    }

    // Format message content
    formatMessage(content) {
        if (typeof content === 'string') {
            return this.md.render(content);
        }

        if (Array.isArray(content)) {
            return content
                .map(item => {
                    if (item.type === 'text') {
                        return this.md.render(item.text);
                    } else if (item.type === 'image') {
                        return `<img src="${item.url}" alt="Generated image" class="generated-image">`;
                    }
                    return '';
                })
                .join('');
        }

        return '';
    }

    // Format error message
    formatErrorMessage(error) {
        return `<div class="error-message">
            <span class="dashicons dashicons-warning"></span>
            <span class="error-text">${error}</span>
        </div>`;
    }

    // Copy message to clipboard
    async copyToClipboard(content) {
        try {
            const text = typeof content === 'string' ? content :
                Array.isArray(content) ? content.map(item => item.text || '').join('\n') :
                content.text || '';

            await navigator.clipboard.writeText(text);

            // Show success indicator
            const notification = this.$('<div>')
                .addClass('copy-notification')
                .text('Copied to clipboard!')
                .appendTo('body');

            setTimeout(() => notification.remove(), 2000);
        } catch (error) {
            console.error('[BedrockChatManager] Copy to clipboard error:', error);
        }
    }

    // Handle stream content
    handleStreamContent(text) {
        const lastMessage = this.elements.messagesContainer.find('.chat-message:last');
        
        if (lastMessage.hasClass('assistant-message')) {
            const content = lastMessage.find('.message-content');
            content.html(this.formatMessage(content.text() + text));
        } else {
            const message = this.createMessageElement('assistant', text);
            this.elements.messagesContainer.append(message);
        }

        this.scrollToBottom();
    }

    // Handle stream complete
    handleStreamComplete() {
        this.setProcessingState(false);
        this.scrollToBottom();
    }

    // Handle error
    handleError(error) {
        console.error('[BedrockChatManager] Error:', error);
        const errorMessage = this.createMessageElement('assistant', error.message || error, true);
        this.elements.messagesContainer.append(errorMessage);
        this.setProcessingState(false);
        this.scrollToBottom();
    }

    // Send message
    async sendMessage() {
        if (this.isProcessing) return;

        const messageText = this.elements.messageInput.val().trim();
        const imagePreview = this.elements.previewImage.attr('src');
        
        // Return if no content to send
        if (!messageText && !imagePreview) return;

        try {
            // Clear input
            this.elements.messageInput.val('');
            this.elements.messageInput.css('height', 'auto');

            // Prepare message content
            const messageContent = [];
            
            // Add text content if present
            if (messageText) {
                messageContent.push({ type: 'text', text: messageText });
            }
            
            // Add image content if present
            if (imagePreview) {
                messageContent.push({ 
                    type: 'image',
                    url: imagePreview
                });
                // Clear image preview
                this.elements.imagePreview.hide();
                this.elements.imageUpload.val('');
                this.elements.previewImage.attr('src', '');
            }

            // Add user message to UI and update message history
            const userMessage = this.createMessageElement('user', messageContent);
            this.elements.messagesContainer.append(userMessage);
            this.messageHistory.push({
                role: 'user',
                content: messageContent
            });

            // Update message count
            this.updateMessageCount();

            // Set processing state
            this.setProcessingState(true);

            // Prepare request
            const requestBody = BedrockAPI.formatRequestBody(
                this.messageHistory,
                {
                    model: this.config.default_model,
                    temperature: Number(this.config.default_temperature || 0.7),
                    system_prompt: this.config.default_system_prompt
                },
                this.selectedTools
            );

            // Send request
            if (this.config.enable_stream) {
                await this.responseHandler.startStreaming(
                    `${this.config.ajaxurl}?action=wpbedrock_chat_message&stream=1&nonce=${this.config.nonce}`,
                    requestBody
                );
            } else {
                const response = await this.$.ajax({
                    url: this.config.ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wpbedrock_chat_message',
                        nonce: this.config.nonce,
                        requestBody: JSON.stringify(requestBody)
                    }
                });

                if (response.success && response.data) {
                    const message = this.createMessageElement('assistant', response.data.content);
                    this.elements.messagesContainer.append(message);
                    this.messageHistory.push({
                        role: 'assistant',
                        content: [{ type: 'text', text: response.data.content }]
                    });
                    this.updateMessageCount();
                } else {
                    throw new Error(response.data || 'Unknown error');
                }

                this.setProcessingState(false);
            }

            this.scrollToBottom();
        } catch (error) {
            this.handleError(error);
        }
    }

    // Set processing state
    setProcessingState(isProcessing) {
        this.isProcessing = isProcessing;
        this.elements.sendButton.prop('disabled', isProcessing);
        this.elements.stopButton.toggle(isProcessing);
        this.elements.messageInput.prop('disabled', isProcessing);
    }

    // Clear chat
    clearChat() {
        this.messageHistory = [];
        this.elements.messagesContainer.empty();
        this.initialize();
    }

    // Update message count
    updateMessageCount() {
        const count = this.messageHistory.length;
        this.elements.messageCountDisplay.text(count);
    }

    // Scroll to bottom
    scrollToBottom() {
        const container = this.elements.messagesContainer[0];
        container.scrollTop = container.scrollHeight;
    }
}

// Export the chat manager class
if (typeof window !== 'undefined' && !window.BedrockChatManager) {
    window.BedrockChatManager = BedrockChatManager;
}
