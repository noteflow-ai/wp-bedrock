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
        
        // Initialize tools configuration
        window.wpbedrock_tools = config.tools || {};

        // Initialize handlers
        this.api = new BedrockAPI();
        this.responseHandler = new BedrockResponseHandler();

        // Set up response handler callbacks based on streaming configuration
        if (this.config.enable_stream) {
            this.responseHandler.setCallbacks({
                onContent: (data) => this.handleStreamContent(data),
                onError: (error) => this.handleError(error),
                onComplete: () => this.handleStreamComplete(),
                onRetry: (retryCount, error) => this.handleRetry(retryCount, error),
                onToolCall: (toolCall) => this.handleToolCall(toolCall),
                onToolResult: (toolResult) => this.handleToolResult(toolResult)
            });
        } else {
            // For non-streaming mode, we need error handling and tool use callbacks
            this.responseHandler.setCallbacks({
                onError: (error) => this.handleError(error),
                onToolCall: (toolCall) => this.handleToolCall(toolCall),
                onToolResult: (toolResult) => this.handleToolResult(toolResult)
            });
        }

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
        const $ = this.$;
        const messageElement = $('<div>')
            .addClass('chat-message')
            .addClass(role === 'assistant' ? 'ai' : (role === 'user' ? 'user' : 'tool'))
            .append(
                $('<div>')
                    .addClass('message-content')
                    .html(isError ? this.responseHandler.formatErrorMessage(content) : this.formatMessage(content))
            );

        // Add image preview styles
        messageElement.find('img.generated-image').css({
            'max-width': '200px',
            'max-height': '200px',
            'object-fit': 'contain',
            'border-radius': '4px'
        });

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

    // Handle stream content with improved tool handling
    handleStreamContent(data) {
        console.log('[BedrockChatManager] Stream content:', data);
        
        // Ensure data is properly structured
        if (!data || !data.type) {
            console.warn('[BedrockChatManager] Invalid content data:', data);
            return;
        }
        
        switch (data.type) {
            case 'text':
                // Ensure content is a string
                if (typeof data.content === 'object') {
                    data = {
                        type: 'text',
                        content: data.content.text || data.content.content || '',
                        role: data.role || 'assistant'
                    };
                }
                this.handleTextContent(data);
                break;

            case 'tool_call':
                this.handleToolCall(data.content);
                break;

            case 'tool_result':
                this.handleToolResult(data.content);
                break;

            default:
                console.warn('[BedrockChatManager] Unknown content type:', data.type);
        }

        this.scrollToBottom();
    }

    // Handle text content
    handleTextContent(data) {
        const content = typeof data.content === 'object' ? data.content.text || data.content.content || '' : data.content || '';
        const role = data.role || 'assistant';
        
        console.log('[BedrockChatManager] Handling text content:', { content, role });

        const lastMessage = this.elements.messagesContainer.find('.chat-message:last');
        if (lastMessage.hasClass('ai') && role === 'assistant') {
            // Update existing assistant message
            const messageContent = lastMessage.find('.message-content');
            const currentText = messageContent.text();
            messageContent.html(this.formatMessage(currentText + content));
            
            // Update message history if this isn't the initial message
            const lastHistoryMessage = this.messageHistory[this.messageHistory.length - 1];
            if (lastHistoryMessage && lastHistoryMessage.role === 'assistant') {
                lastHistoryMessage.content = [{ 
                    type: 'text', 
                    text: currentText + content 
                }];
            }
        } else if (content) { // Only create new message if we have content
            // Create new message
            const message = this.createMessageElement(role, content);
            this.elements.messagesContainer.append(message);
            
            // Only add to history if we have at least one user message
            if (this.messageHistory.some(msg => msg.role === 'user')) {
                this.messageHistory.push({
                    role: role,
                    content: [{ type: 'text', text: content }]
                });
            }
        }
    }

    // Handle tool call
    handleToolCall(toolCall) {
        console.log('[BedrockChatManager] Tool call:', toolCall);
        
        // Format tool call for Claude
        const formattedToolCall = {
            role: 'assistant',
            content: [{
                type: 'tool_use',
                id: `call_${Date.now()}`,
                name: toolCall.name,
                input: typeof toolCall.arguments === 'string' ? 
                    JSON.parse(toolCall.arguments) : toolCall.arguments
            }]
        };

        // Create tool call message element
        const message = this.createMessageElement(
            'tool',
            this.formatToolMessage({
                type: 'tool_call',
                name: toolCall.name,
                arguments: toolCall.arguments
            })
        );
        this.elements.messagesContainer.append(message);

        // Add to message history
        if (this.messageHistory.some(msg => msg.role === 'user')) {
            this.messageHistory.push(formattedToolCall);
        }

        // Execute tool call
        Promise.all([formattedToolCall.content[0]].map(tool => {
            return this.executeToolCall(tool.name, tool.input)
                .then(result => ({
                    tool_call_id: tool.id,
                    name: tool.name,
                    content: result
                }));
        })).then(toolResults => {
            toolResults.forEach(result => this.handleToolResult(result));
        }).catch(error => this.handleError(error));
    }

    // Handle tool result
    handleToolResult(toolResult) {
        console.log('[BedrockChatManager] Tool result:', toolResult);
        
        // Format tool result for Claude
        const formattedToolResult = {
            role: 'user',
            content: [{
                type: 'tool_result',
                tool_use_id: toolResult.tool_call_id,
                content: toolResult.content
            }]
        };

        // Create tool result message element
        const message = this.createMessageElement(
            'tool',
            this.formatToolMessage({
                type: 'tool_result',
                name: toolResult.name,
                result: toolResult.content
            })
        );
        this.elements.messagesContainer.append(message);

        // Add to message history
        if (this.messageHistory.some(msg => msg.role === 'user')) {
            this.messageHistory.push(formattedToolResult);
        }

        // Process tool message and continue conversation
        const requestBody = BedrockAPI.prepareRequestMessages(this.config, this.messageHistory, this.selectedTools);
        this.sendMessage();
    }

    // Format tool message for display
    formatToolMessage(toolData) {
        const type = toolData.type === 'tool_call' ? 'Tool Call' : 'Tool Result';
        const content = toolData.type === 'tool_call' ? 
            `Arguments: ${JSON.stringify(toolData.arguments, null, 2)}` :
            `Result: ${JSON.stringify(toolData.result, null, 2)}`;

        return `<div class="tool-message">
            <div class="tool-header">
                <span class="tool-type">${type}</span>
                <span class="tool-name">${toolData.name}</span>
            </div>
            <pre class="tool-content"><code>${content}</code></pre>
        </div>`;
    }

    // Get current turn index
    getCurrentTurnIndex() {
        return Math.floor(this.messageHistory.filter(msg => 
            msg.role === 'user' || msg.role === 'assistant'
        ).length / 2);
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

        // Get message text and ensure it's a string
        const messageText = String(this.elements.messageInput.val() || '').trim();
        const imagePreview = this.elements.previewImage.attr('src');
        
        console.log('[BedrockChatManager] Sending message:', { messageText, imagePreview });
        
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
            // Prepare message content before clearing input
            const messageContent = BedrockAPI.prepareMessageContent(messageText, imagePreview);
            console.log('[BedrockChatManager] Prepared content:', messageContent);

            // Add user message to UI and history
            this.addUserMessage(messageContent);
            
            // Clear input and reset state
            this.clearInput(messageText, imagePreview);

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
        // Create message element with proper formatting
        const userMessage = this.createMessageElement('user', messageContent);
        this.elements.messagesContainer.append(userMessage);

        // Format message content for history
        let historyContent = Array.isArray(messageContent) ? messageContent : [messageContent];
        
        // Ensure each content item has proper type and format
        historyContent = historyContent.map(item => {
            if (typeof item === 'string') {
                return { type: 'text', text: item };
            }
            if (item.type === 'image' && item.image_url) {
                return {
                    type: 'image',
                    image_url: {
                        url: item.image_url.url
                    }
                };
            }
            return item;
        });

        // Add to message history
        this.messageHistory.push({
            role: 'user',
            content: historyContent
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
                // Handle Claude's array-based content format
                if (Array.isArray(response.data.content)) {
                    for (const item of response.data.content) {
                        if (item.type === 'text') {
                            // Handle text content
                            this.handleTextContent({
                                type: 'text',
                                content: item.text,
                                role: 'assistant'
                            });
                        } else if (item.type === 'tool_use') {
                            // Generate tool call ID
                            const toolCallId = `call_${Date.now()}`;
                            
                            // First display the tool call
                            this.handleToolCall({
                                id: toolCallId,
                                name: item.name,
                                arguments: item.input
                            });
                            
                            // Execute the tool call
                            const toolResult = await this.executeToolCall(item.name, item.input);
                            
                            // Display tool result
                            this.handleToolResult({
                                tool_call_id: toolCallId,
                                name: item.name,
                                content: toolResult
                            });
                            
                            // Send result back to Claude
                            const toolResponse = await this.sendNonStreamingRequest({
                                messages: [
                                    ...this.messageHistory,
                                    {
                                        role: 'user',
                                        content: [{
                                            type: 'tool_result',
                                            tool_use_id: `call_${Date.now()}`,
                                            content: toolResult
                                        }]
                                    }
                                ]
                            });
                            
                            // Handle Claude's response to tool result
                            await this.handleNonStreamingResponse(toolResponse);
                        }
                    }
                    this.updateMessageCount();
                    this.setProcessingState(false);
                    this.scrollToBottom();
                    return;
                }

                // Handle single tool use response
                if (response.data.type === 'tool_use') {
                    // Generate tool call ID
                    const toolCallId = `call_${Date.now()}`;
                    
                    // First display the tool call
                    this.handleToolCall({
                        id: toolCallId,
                        name: response.data.name,
                        arguments: response.data.input
                    });
                    
                    // Execute the tool call
                    const toolResult = await this.executeToolCall(response.data.name, response.data.input);
                    
                    // Display tool result
                    this.handleToolResult({
                        tool_call_id: toolCallId,
                        name: response.data.name,
                        content: toolResult
                    });
                    
                    // Send result back to Claude
                    const toolResponse = await this.sendNonStreamingRequest({
                        messages: [
                            ...this.messageHistory,
                            {
                                role: 'user',
                                content: [{
                                    type: 'tool_result',
                                    tool_use_id: `call_${Date.now()}`,
                                    content: toolResult
                                }]
                            }
                        ]
                    });
                    
                    // Handle Claude's response to tool result
                    await this.handleNonStreamingResponse(toolResponse);
                    return;
                }
                
                // Handle regular content responses
                let content = '';
                
                // For Nova responses
                if (response.data.output?.message) {
                    content = response.data.output.message.content?.[0]?.text || '';
                }
                // For Llama responses
                else if (response.data.generation) {
                    content = response.data.generation;
                }
                // For Mistral responses
                else if (response.data.choices?.[0]?.message?.content) {
                    content = response.data.choices[0].message.content;
                }
                // For Claude single content response
                else if (response.data.content && !Array.isArray(response.data.content)) {
                    content = response.data.content;
                }
                
                if (content) {
                    this.handleTextContent({
                        type: 'text',
                        content: content,
                        role: 'assistant'
                    });
                    this.updateMessageCount();
                }
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

    // Execute tool call with retries and timeout
    async executeToolCall(toolName, args) {
        console.log('[BedrockChatManager] Executing tool call:', { toolName, args });
        
        const maxRetries = 3;
        const timeout = 30000; // 30 seconds
        const retryDelay = 1000; // 1 second

        for (let attempt = 1; attempt <= maxRetries; attempt++) {
            try {
                // Get tool configuration
                const normalizedToolName = toolName.toLowerCase().replace(/[_-]/g, ' ');
                const toolConfig = window.wpbedrock_tools?.tools?.find(tool => 
                    tool.info.title.toLowerCase() === normalizedToolName
                );

                if (!toolConfig) {
                    throw new Error(`Tool ${toolName} not found in configuration`);
                }

                // Get tool endpoint info
                const pathKey = Object.keys(toolConfig.paths)[0];
                const methodKey = Object.keys(toolConfig.paths[pathKey])[0];
                const serverUrl = toolConfig.servers[0].url;

                // Build request URL and parameters
                const url = (serverUrl + pathKey).replace(/\/+$/, ''); // Remove trailing slashes
                
                // Ensure args are properly formatted before sending to proxy
                const formattedArgs = {};
                // Convert args from string to object if needed
                const argsObj = typeof args === 'string' ? JSON.parse(args) : args;
                
                // Format each parameter according to the tool's schema
                Object.entries(argsObj).forEach(([key, value]) => {
                    formattedArgs[key] = typeof value === 'string' ? value : JSON.stringify(value);
                });

                // Make request through WordPress proxy
                const response = await this.$.ajax({
                    url: this.config.ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wpbedrock_tool_proxy',
                        nonce: this.config.nonce,
                        url: url,
                        method: methodKey,
                        params: formattedArgs
                    }
                });

                if (!response || !response.success) {
                    throw new Error(response?.data || 'Tool execution failed');
                }

                return response.data;
            } catch (error) {
                if (attempt === maxRetries) {
                    throw error;
                }
                console.warn(`[BedrockChatManager] Tool execution attempt ${attempt} failed:`, error);
                await new Promise(resolve => setTimeout(resolve, retryDelay * attempt));
            }
        }
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
