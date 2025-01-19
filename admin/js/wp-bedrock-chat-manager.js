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
        this.responseHandler = new BedrockResponseHandler();

        // Set up response handler callbacks
        this.responseHandler.setCallbacks({
            onContent: (data) => this.handleStreamContent(data),
            onError: (error) => this.handleError(error),
            onComplete: () => this.handleStreamComplete(),
            onRetry: (retryCount, error) => this.handleRetry(retryCount, error)
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
        // Reset message history
        this.messageHistory = [];
        
        // Create initial message UI without adding to history
        if (this.config.initial_message) {
            const message = this.createMessageElement('assistant', this.config.initial_message);
            this.elements.messagesContainer.append(message);
        }

        // Set placeholder
        if (this.config.placeholder) {
            this.elements.messageInput.attr('placeholder', this.config.placeholder);
        }

        // Update message count (should be 0 since initial message isn't counted)
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
                    .html(isError ? this.responseHandler.formatErrorMessage(content) : this.formatMessage(content))
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

    // Format message content using BedrockAPI
    formatMessage(content) {
        return BedrockAPI.formatMessageContent(content, this.md);
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
    handleStreamContent(data) {
        switch (data.type) {
            case 'text':
                const lastMessage = this.elements.messagesContainer.find('.chat-message:last');
                if (lastMessage.hasClass('assistant-message')) {
                    // Update existing assistant message
                    const messageContent = lastMessage.find('.message-content');
                    const currentText = messageContent.text();
                    messageContent.html(this.formatMessage(currentText + data.content));
                    
                    // Update message history if this isn't the initial message
                    const lastHistoryMessage = this.messageHistory[this.messageHistory.length - 1];
                    if (lastHistoryMessage && lastHistoryMessage.role === 'assistant') {
                        lastHistoryMessage.content = [{ 
                            type: 'text', 
                            text: currentText + data.content 
                        }];
                    }
                } else {
                    // Create new assistant message
                    const message = this.createMessageElement('assistant', data.content);
                    this.elements.messagesContainer.append(message);
                    
                    // Only add to history if we have at least one user message
                    if (this.messageHistory.some(msg => msg.role === 'user')) {
                        this.messageHistory.push({
                            role: 'assistant',
                            content: [{ type: 'text', text: data.content }]
                        });
                    }
                }
                break;

            case 'tool_call':
            case 'tool_result':
                // Only add tool messages if we have at least one user message
                if (this.messageHistory.some(msg => msg.role === 'user')) {
                    this.messageHistory.push({
                        role: 'tool',
                        content: [{ 
                            type: 'text', 
                            text: JSON.stringify(data.content, null, 2) 
                        }]
                    });
                }
                const toolMessage = this.createMessageElement(
                    'tool',
                    JSON.stringify(data.content, null, 2)
                );
                this.elements.messagesContainer.append(toolMessage);
                break;

            default:
                console.warn('[BedrockChatManager] Unknown content type:', data.type);
        }

        this.scrollToBottom();
    }

    // Handle retry attempts
    handleRetry(retryCount, error) {
        const retryMessage = this.createMessageElement(
            'system',
            `Retrying connection (attempt ${retryCount}/3)...`
        );
        this.elements.messagesContainer.append(retryMessage);
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
        const errorMessage = this.createMessageElement('assistant', error, true);
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

        // Ensure model is set
        if (!this.config.default_model) {
            this.handleError({
                code: 'CONFIG_ERROR',
                message: 'No model configured. Please check your settings.'
            });
            return;
        }

        try {
            // Clear input and reset state
            this.clearInput(messageText, imagePreview);

            // Prepare and add user message
            const messageContent = BedrockAPI.prepareMessageContent(messageText, imagePreview);
            this.addUserMessage(messageContent);

            // Set processing state
            this.setProcessingState(true);

            // Prepare request
            const requestBody = BedrockAPI.prepareRequestMessages(this.config, this.messageHistory, this.selectedTools);

            // Send request based on streaming configuration
            if (this.config.enable_stream) {
                const streamUrl = this.getStreamUrl();
                await this.responseHandler.startStreaming(streamUrl, requestBody);
            } else {
                const response = await this.sendNonStreamingRequest(requestBody);
                await this.handleNonStreamingResponse(response);
            }

        } catch (error) {
            this.handleError(error);
        }
    }

    // Clear input fields
    clearInput(messageText, imagePreview) {
        this.elements.messageInput.val('');
        this.elements.messageInput.css('height', 'auto');

        if (imagePreview) {
            this.elements.imagePreview.hide();
            this.elements.imageUpload.val('');
            this.elements.previewImage.attr('src', '');
        }
    }

    // Add user message to UI and history
    addUserMessage(messageContent) {
        const userMessage = this.createMessageElement('user', messageContent);
        this.elements.messagesContainer.append(userMessage);
        this.messageHistory.push({
            role: 'user',
            content: messageContent
        });
        this.updateMessageCount();
    }

    // Get stream URL
    getStreamUrl() {
        return `${this.config.ajaxurl}?action=wpbedrock_chat_message&stream=1&nonce=${this.config.nonce}`;
    }

    // Send non-streaming request
    async sendNonStreamingRequest(requestBody) {
        const response = await this.$.ajax({
            url: this.config.ajaxurl,
            method: 'POST',
            data: {
                action: 'wpbedrock_chat_message',
                nonce: this.config.nonce,
                requestBody: JSON.stringify(requestBody)
            }
        });

        if (!response.success) {
            throw new Error(response.data || 'Unknown error');
        }

        return response;
    }

    // Handle non-streaming response
    async handleNonStreamingResponse(response) {
        try {
            if (!response || !response.success) {
                throw new Error(response?.data || 'Invalid response from server');
            }
            
            if (response.data) {
                const normalizedResponse = await this.responseHandler.handleResponse(response.data);
                
                // Use handleStreamContent to ensure consistent message handling
                this.handleStreamContent(normalizedResponse);
                
                // Message history is already updated by handleStreamContent
                this.updateMessageCount();
            }
            this.setProcessingState(false);
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
        this.elements.messageCountDisplay.text(`${count} messages`);
    }

    // Scroll to bottom
    scrollToBottom() {
        const container = this.elements.messagesContainer[0];
        container.scrollTop = container.scrollHeight;
    }

    // Toggle tool selection
    toggleTool(toolDefinition) {
        const index = this.selectedTools.findIndex(t => 
            t.info && t.info.title === toolDefinition.info.title
        );
        if (index === -1) {
            this.selectedTools.push(toolDefinition);
        } else {
            this.selectedTools.splice(index, 1);
        }
    }
}

// Export the chat manager class
if (typeof window !== 'undefined' && !window.BedrockChatManager) {
    window.BedrockChatManager = BedrockChatManager;
}
