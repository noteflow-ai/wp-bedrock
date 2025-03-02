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

        // Remove any existing loading messages
        if (elements.messagesContainer) {
            elements.messagesContainer.find('.loading-message').remove();
        }
        
        // Initialize state
        this.messageHistory = [];
        this.selectedTools = [];
        this.isProcessing = false;
        this.currentEventSource = null;
        
        // Initialize tools configuration
        window.wpbedrock_tools = {
            tools: config.tools?.tools || []
        };

        // Initialize handlers
        this.api = new BedrockAPI();
        this.responseHandler = new BedrockResponseHandler();

        // Set up response handler callbacks based on streaming configuration
        if (this.config.enable_stream) {
            this.responseHandler.setCallbacks({
                onContent: (data) => this.handleContent(data, true),
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
        // Remove any existing loading messages
        this.elements.messagesContainer.find('.loading-message').remove();
        
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
                    .html(isError ? this.responseHandler.formatErrorMessage(content) : this.formatMessage(content, this.config.default_model))
            );

        // Add loading class if this is a loading message
        if (content === 'Searching...' || content === 'Thinking...') {
            messageElement.addClass('loading-message');
        }

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
    formatMessage(content, modelId) {
        // Remove any existing loading messages before formatting message
        this.elements.messagesContainer.find('.loading-message').remove();
        
        return BedrockAPI.formatMessageContent(content, this.md, modelId);
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

    // Show text preview
    showTextPreview(content) {
        if (!this.elements.textPreview || !this.elements.previewContent) {
            console.warn('[BedrockChatManager] Text preview elements not found');
            return;
        }
        
        this.elements.previewContent.html(content);
        this.elements.textPreview.removeClass('hidden');
    }

    // Hide text preview
    hideTextPreview() {
        if (!this.elements.textPreview) {
            return;
        }
        
        this.elements.textPreview.addClass('hidden');
        if (this.elements.previewContent) {
            this.elements.previewContent.html('');
        }
    }

    // Handle content from both streaming and non-streaming responses
    handleContent(data, isStreaming = false) {
        console.log(`[BedrockChatManager] ${isStreaming ? 'Stream' : 'Non-stream'} content:`, data);
        
        // Remove any existing loading messages before handling new content
        this.elements.messagesContainer.find('.loading-message').remove();
        
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
                this.handleTextContent(data, isStreaming);
                break;

            case 'tool_call':
                this.handleToolCall(data.content);
                break;

            case 'tool_result':
                // Mark streaming tool results
                if (isStreaming) {
                    data.content.isStreaming = true;
                }
                this.handleToolResult(data.content);
                break;

            default:
                console.warn('[BedrockChatManager] Unknown content type:', data.type);
        }

        this.scrollToBottom();
    }

    // Handle text content for both streaming and non-streaming
    handleTextContent(data, isStreaming = false) {
        const content = typeof data.content === 'object' ? data.content.text || data.content.content || '' : data.content || '';
        const role = data.role || 'assistant';
        
        console.log('[BedrockChatManager] Handling text content:', { content, role, isStreaming });

        // Remove any existing loading messages before handling new content
        this.elements.messagesContainer.find('.loading-message').remove();

        const lastMessage = this.elements.messagesContainer.find('.chat-message:last');
        if (lastMessage.hasClass('ai') && role === 'assistant' && isStreaming) {
            // Update existing assistant message for streaming
            const messageContent = lastMessage.find('.message-content');
            const currentText = messageContent.text();
            messageContent.html(this.formatMessage(currentText + content));
            
            // Update message history if this isn't the initial message
            const lastHistoryMessage = this.messageHistory[this.messageHistory.length - 1];
            if (lastHistoryMessage && lastHistoryMessage.role === 'assistant') {
                lastHistoryMessage.content = [{ 
                    type: 'text', 
                    text: currentText + content || ';' // Ensure non-empty content
                }];
            }
        } else { // Create new message for non-streaming or new streaming message
            // Create new message if there's content or it's a required message
            const message = this.createMessageElement(role, content || ';');
            this.elements.messagesContainer.append(message);
            
            // Only add to history if we have at least one user message
            if (this.messageHistory.some(msg => msg.role === 'user')) {
                this.messageHistory.push({
                    role: role,
                    content: [{ 
                        type: 'text', 
                        text: content || ';' // Ensure non-empty content
                    }]
                });
            }
        }
    }


    // Handle tool call
    async handleToolCall(toolCall) {
        console.log('[BedrockChatManager] Tool call:', toolCall);
        
        const toolCallId = `call_${Date.now()}`;
        const modelId = this.config.default_model;
        const isMistral = modelId.includes('mistral.mistral');
        const isClaude = modelId.includes('anthropic.claude');
        const isNova = modelId.includes('amazon.nova');

        // First add the tool call to history
        if (isClaude) {
            this.messageHistory.push({
                role: 'assistant',
                content: [{
                    type: 'tool_use',
                    id: toolCallId,
                    name: toolCall.name,
                    input: typeof toolCall.arguments === 'string' ? 
                        JSON.parse(toolCall.arguments) : 
                        toolCall.arguments
                }]
            });
        } else if (isMistral) {
            this.messageHistory.push({
                role: 'assistant',
                content: '',
                tool_calls: [{
                    id: toolCallId,
                    function: {
                        name: toolCall.name,
                        arguments: typeof toolCall.arguments === 'string' ? 
                            toolCall.arguments : 
                            JSON.stringify(toolCall.arguments)
                    }
                }]
            });
        } else if (isNova) {
            this.messageHistory.push({
                role: 'assistant',
                content: [{
                    toolUse: {
                        toolUseId: toolCallId,
                        name: toolCall.name,
                        input: typeof toolCall.arguments === 'string' ? 
                            JSON.parse(toolCall.arguments) : 
                            toolCall.arguments
                    }
                }]
            });
        }
        
        try {
            // Create and display tool call message
            const toolCallMessage = this.createMessageElement('tool', {
                type: 'tool_call',
                name: toolCall.name,
                arguments: toolCall.arguments
            });
            this.elements.messagesContainer.append(toolCallMessage);
            this.scrollToBottom();

            // Add a loading message while executing tool
            const loadingMessage = this.createMessageElement(
                'assistant',
                'Searching...'
            );
            this.elements.messagesContainer.append(loadingMessage);
            this.scrollToBottom();

            let result;
            try {
                // Execute tool call
                result = await this.executeToolCall(toolCall.name, toolCall.arguments);

                // Remove loading message
                loadingMessage.remove();

                // Format result based on tool type
                if (toolCall.name === 'arxiv_search') {
                    result = {
                        type: 'search_results',
                        query: toolCall.arguments.search_query,
                        results: result.map(paper => ({
                            title: paper.title,
                            snippet: paper.summary,
                            url: paper.pdf_url || paper.url,
                            time: new Date(paper.published).toLocaleDateString()
                        }))
                    };
                }

                // Create and display tool result message
                const toolResultMessage = this.createMessageElement('tool', {
                    type: 'tool_result',
                    name: toolCall.name,
                    output: result
                });
                this.elements.messagesContainer.append(toolResultMessage);
                this.scrollToBottom();

                // Add tool result to history
                if (isClaude) {
                    this.messageHistory.push({
                        role: 'user',
                        content: [{
                            type: 'tool_result',
                            tool_use_id: toolCallId,
                            content: result
                        }]
                    });
                } else if (isMistral) {
                    this.messageHistory.push({
                        role: 'tool',
                        tool_call_id: toolCallId,
                        content: typeof result === 'string' ? 
                            result : JSON.stringify(result)
                    });
                } else if (isNova) {
                    this.messageHistory.push({
                        role: 'user',
                        content: [{
                            toolResult: {
                                toolUseId: toolCallId,
                                content: [{
                                    json: {
                                        content: result
                                    }
                                }]
                            }
                        }]
                    });
                }

                // Get model's response to tool result
                const requestBody = BedrockAPI.formatRequestBody(
                    this.messageHistory,
                    {
                        model: this.config.default_model,
                        temperature: Number(this.config.default_temperature || 0.7),
                        max_tokens: 2000,
                        top_p: 0.9,
                        anthropic_version: "bedrock-2023-05-31"
                    },
                    this.selectedTools
                );

                // Add a loading message while waiting for response
                const thinkingMessage = this.createMessageElement(
                    'assistant',
                    'Thinking...'
                );
                this.elements.messagesContainer.append(thinkingMessage);
                this.scrollToBottom();

                // Send non-streaming request
                const response = await this.sendNonStreamingRequest(requestBody);
                
                // Remove thinking message before handling response
                thinkingMessage.remove();
                
                await this.handleResponse(response, false);

            } catch (error) {
                // Remove loading message
                loadingMessage.remove();

                // If tool execution fails, create an error result
                result = {
                    error: true,
                    message: error.message || 'Tool execution failed'
                };

                // Display error message
                const errorMessage = this.createMessageElement('tool', {
                    type: 'tool_result',
                    name: toolCall.name,
                    output: { error: error.message }
                });
                this.elements.messagesContainer.append(errorMessage);
                this.scrollToBottom();

                // Add error result to history
                if (isClaude) {
                    this.messageHistory.push({
                        role: 'user',
                        content: [{
                            type: 'tool_result',
                            tool_use_id: toolCallId,
                            content: { error: error.message }
                        }]
                    });
                } else if (isMistral) {
                    this.messageHistory.push({
                        role: 'tool',
                        tool_call_id: toolCallId,
                        content: JSON.stringify({ error: error.message })
                    });
                } else if (isNova) {
                    this.messageHistory.push({
                        role: 'user',
                        content: [{
                            toolResult: {
                                toolUseId: toolCallId,
                                content: [{
                                    json: {
                                        content: { error: error.message }
                                    }
                                }]
                            }
                        }]
                    });
                }

                // Get model's response to error
                const requestBody = BedrockAPI.formatRequestBody(
                    this.messageHistory,
                    {
                        model: this.config.default_model,
                        temperature: Number(this.config.default_temperature || 0.7),
                        max_tokens: 2000,
                        top_p: 0.9,
                        anthropic_version: "bedrock-2023-05-31"
                    },
                    this.selectedTools
                );

                // Add a loading message while waiting for response
                const thinkingMessage = this.createMessageElement(
                    'assistant',
                    'Thinking...'
                );
                this.elements.messagesContainer.append(thinkingMessage);
                this.scrollToBottom();

                // Send non-streaming request
                const response = await this.sendNonStreamingRequest(requestBody);
                
                // Remove thinking message before handling response
                thinkingMessage.remove();
                
                await this.handleResponse(response, false);
            }

            this.updateMessageCount();
            this.scrollToBottom();
        } catch (error) {
            this.handleError(error);
            this.setProcessingState(false);
        }
    }

    // Handle tool result
    async handleToolResult(toolResult) {
        console.log('[BedrockChatManager] Tool result:', toolResult);
        
        try {
            const toolCallId = toolResult.tool_call_id || `call_${Date.now()}`;
            const modelId = this.config.default_model;
            const isMistral = modelId.includes('mistral.mistral');
            const isClaude = modelId.includes('anthropic.claude');
            const isNova = modelId.includes('amazon.nova');

            // Format tool result for message history
            let formattedResult;
            if (isClaude) {
                formattedResult = {
                    role: 'user',
                    content: [{
                        type: 'tool_result',
                        tool_use_id: toolCallId,
                        content: toolResult.error ? 
                            { error: toolResult.message } : 
                            toolResult.output || toolResult.content || toolResult
                    }]
                };
            } else if (isMistral) {
                formattedResult = {
                    role: 'tool',
                    tool_call_id: toolCallId,
                    content: typeof toolResult.output === 'string' ? 
                        toolResult.output : 
                        JSON.stringify(toolResult.output || toolResult.content || toolResult)
                };
            } else if (isNova) {
                formattedResult = {
                    role: 'user',
                    content: [{
                        toolResult: {
                            toolUseId: toolCallId,
                            content: [{
                                json: {
                                    content: toolResult.error ? 
                                        { error: toolResult.message } : 
                                        toolResult.output || toolResult.content || toolResult
                                }
                            }]
                        }
                    }]
                };
            }

            // Add formatted result to message history and display in UI
            if (formattedResult) {
                this.messageHistory.push(formattedResult);
                
                // Create and display tool result message
                const message = this.createMessageElement('tool', {
                    type: 'tool_result',
                    name: toolResult.name || 'Tool Result',
                    output: toolResult.error ? 
                        { error: toolResult.message } : 
                        toolResult.output || toolResult.content || toolResult
                });
                this.elements.messagesContainer.append(message);
                this.scrollToBottom();

                // Get model's response to tool result
                const requestBody = BedrockAPI.formatRequestBody(
                    this.messageHistory,
                    {
                        model: this.config.default_model,
                        temperature: Number(this.config.default_temperature || 0.7),
                        max_tokens: 2000,
                        top_p: 0.9,
                        anthropic_version: "bedrock-2023-05-31"
                    },
                    this.selectedTools
                );
                
                // Add a loading message while waiting for response
                const loadingMessage = this.createMessageElement(
                    'assistant',
                    'Thinking...'
                );
                this.elements.messagesContainer.append(loadingMessage);
                this.scrollToBottom();
                
                try {
                    // Send non-streaming request
                    const response = await this.sendNonStreamingRequest(requestBody);
                    
                    // Remove loading message before handling response
                    loadingMessage.remove();
                    
                    await this.handleResponse(response, false);
                } catch (error) {
                    // Remove loading message on error
                    loadingMessage.remove();
                    throw error;
                }

                this.updateMessageCount();
                this.scrollToBottom();
            }
        } catch (error) {
            this.handleError(error);
            this.setProcessingState(false);
        }
    }

    // Format tool message for display
    formatToolMessage(toolData) {
        // Remove any existing loading messages before formatting tool message
        this.elements.messagesContainer.find('.loading-message').remove();
        
        const type = toolData.type === 'tool_call' ? 'Tool Call' : 'Tool Result';
        let content;
        
        if (toolData.type === 'tool_call') {
            const args = typeof toolData.arguments === 'string' ? 
                JSON.parse(toolData.arguments) : 
                toolData.arguments;
            content = `<div class="tool-arguments">
                <div class="tool-section-header">Arguments:</div>
                <pre class="tool-code"><code>${JSON.stringify(args, null, 2)}</code></pre>
            </div>`;
        } else {
            // For tool results, look for output in various locations
            const output = toolData.output || toolData.content?.output || toolData.result || toolData.content;
            
            // Handle search results specially
            if (output?.type === 'search_results' && Array.isArray(output.results)) {
                content = `<div class="tool-results">
                    <div class="tool-section-header">Search Results for: "${output.query}"</div>
                    ${output.results.map(result => `
                        <div class="search-result">
                            <div class="result-title">${result.title}</div>
                            <div class="result-snippet">${result.snippet}</div>
                            <div class="result-url">${result.url}</div>
                            ${result.time ? `<div class="result-time">${result.time}</div>` : ''}
                        </div>
                    `).join('')}
                </div>`;
            } else if (output?.error) {
                content = `<div class="tool-error">
                    <div class="tool-section-header">Error:</div>
                    <pre class="tool-code error"><code>${output.error}</code></pre>
                </div>`;
            } else {
                content = `<div class="tool-results">
                    <div class="tool-section-header">Result:</div>
                    <pre class="tool-code"><code>${JSON.stringify(output, null, 2)}</code></pre>
                </div>`;
            }
        }

        return `<div class="tool-message ${toolData.type}">
            <div class="tool-header">
                <span class="tool-icon">${type === 'Tool Call' ? '🔧' : '📋'}</span>
                <span class="tool-type">${type}</span>
                <span class="tool-name">${toolData.name}</span>
            </div>
            ${content}
        </div>`;
    }

    // Get current turn index
    getCurrentTurnIndex() {
        // Remove any existing loading messages before getting turn index
        this.elements.messagesContainer.find('.loading-message').remove();
        
        return Math.floor(this.messageHistory.filter(msg => 
            msg.role === 'user' || msg.role === 'assistant'
        ).length / 2);
    }

    // Handle retry attempts
    handleRetry(retryCount, error) {
        // Remove any existing loading or retry messages
        this.elements.messagesContainer.find('.loading-message').remove();
        
        const retryMessage = this.createMessageElement(
            'system',
            `Retrying connection (attempt ${retryCount}/3)...`
        );
        // Add loading class to retry message for cleanup
        retryMessage.addClass('loading-message');
        this.elements.messagesContainer.append(retryMessage);
        this.scrollToBottom();
    }

    // Handle stream complete
    handleStreamComplete() {
        // Remove any existing loading messages
        this.elements.messagesContainer.find('.loading-message').remove();
        
        this.setProcessingState(false);
        this.scrollToBottom();
    }

    // Handle error
    handleError(error) {
        console.error('[BedrockChatManager] Error:', error);
        
        // Remove any existing loading messages
        this.elements.messagesContainer.find('.loading-message').remove();
        
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

            // Remove any existing loading messages before starting new request
            this.elements.messagesContainer.find('.loading-message').remove();
            
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
                await this.handleResponse(response, false);
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
        // Remove any existing loading messages before adding new message
        this.elements.messagesContainer.find('.loading-message').remove();
        
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

    // Handle response from both streaming and non-streaming
    async handleResponse(response, isStreaming = false) {
        try {
            // Remove any existing loading messages before processing response
            this.elements.messagesContainer.find('.loading-message').remove();
            
            if (!response || !response.success) {
                throw new Error(response?.data || 'Invalid response from server');
            }

            const modelId = this.config.default_model;
            const isMistral = modelId.includes('mistral.mistral');
            const isClaude = modelId.includes('anthropic.claude');
            const isNova = modelId.includes('amazon.nova');
            
            if (response.data) {
                // Handle Nova model responses
                if (isNova && response.data.output?.message) {
                    const message = response.data.output.message;
                    
                    // Process all content items in order
                    if (Array.isArray(message.content)) {
                        for (const item of message.content) {
                            if (item.text) {
                                // Handle text content
                                this.handleContent({
                                    type: 'text',
                                    content: item.text,
                                    role: 'assistant'
                                }, isStreaming);
                            } else if (item.toolUse) {
                                // Handle tool use
                                const toolCallId = `call_${Date.now()}`;
                                const toolCall = {
                                    id: toolCallId,
                                    name: item.toolUse.name,
                                    arguments: item.toolUse.input
                                };

                                // Add to message history
                                this.messageHistory.push({
                                    role: 'assistant',
                                    content: [{
                                        toolUse: {
                                            toolUseId: toolCallId,
                                            name: item.toolUse.name,
                                            input: item.toolUse.input
                                        }
                                    }]
                                });

                                // Execute tool
                                const result = await this.executeToolCall(toolCall.name, toolCall.arguments);

                                // Add result to history
                                this.messageHistory.push({
                                    role: 'user',
                                    content: [{
                                        toolResult: {
                                            toolUseId: toolCallId,
                                            content: [{
                                                json: {
                                                    content: result
                                                }
                                            }]
                                        }
                                    }]
                                });
                            }
                        }
                    }
                }
                // Handle Claude's array-based content format
                else if (isClaude && Array.isArray(response.data.content)) {
                    for (const item of response.data.content) {
                        if (item.type === 'text') {
                            // Handle text content
                            this.handleContent({
                                type: 'text',
                                content: item.text,
                                role: 'assistant'
                            }, isStreaming);
                        } else if (item.type === 'tool_use') {
                            // Handle tool use
                            const toolCallId = item.id || `call_${Date.now()}`;
                            const toolCall = {
                                id: toolCallId,
                                name: item.name,
                                arguments: item.input
                            };

                            // Add to message history
                            this.messageHistory.push({
                                role: 'assistant',
                                content: [{
                                    type: 'tool_use',
                                    id: toolCallId,
                                    name: item.name,
                                    input: typeof item.input === 'string' ? 
                                        JSON.parse(item.input) : item.input
                                }]
                            });

                            // Execute tool
                            const result = await this.executeToolCall(toolCall.name, toolCall.arguments);

                            // Add result to history
                            this.messageHistory.push({
                                role: 'user',
                                content: [{
                                    type: 'tool_result',
                                    tool_use_id: toolCallId,
                                    content: result
                                }]
                            });
                        }
                    }
                }
                // Handle Mistral responses
                else if (isMistral && response.data.tool_calls) {
                    for (const tool of response.data.tool_calls) {
                        // Handle tool use
                        const toolCallId = tool.id || `call_${Date.now()}`;
                        const toolCall = {
                            id: toolCallId,
                            name: tool.function.name,
                            arguments: tool.function.arguments
                        };

                        // Add to message history
                        this.messageHistory.push({
                            role: 'assistant',
                            content: '',
                            tool_calls: [{
                                id: toolCallId,
                                function: {
                                    name: tool.function.name,
                                    arguments: typeof tool.function.arguments === 'string' ? 
                                        tool.function.arguments : 
                                        JSON.stringify(tool.function.arguments)
                                }
                            }]
                        });

                        // Execute tool
                        const result = await this.executeToolCall(toolCall.name, toolCall.arguments);

                        // Add result to history
                        this.messageHistory.push({
                            role: 'tool',
                            tool_call_id: toolCallId,
                            content: typeof result === 'string' ? 
                                result : JSON.stringify(result)
                        });
                    }
                }
                // Handle regular content responses
                else {
                    let content = '';
                    
                    // Extract content based on model type
                    if (response.data.generation) {
                        content = response.data.generation;
                    } else if (response.data.choices?.[0]?.message?.content) {
                        content = response.data.choices[0].message.content;
                    } else if (response.data.content && !Array.isArray(response.data.content)) {
                        content = response.data.content;
                    }
                    
                    if (content) {
                        this.handleContent({
                            type: 'text',
                            content: content,
                            role: 'assistant'
                        }, isStreaming);
                    }
                }
            }
            
            this.updateMessageCount();
            if (!isStreaming) {
                this.setProcessingState(false);
            }
            this.scrollToBottom();
        } catch (error) {
            this.handleError(error);
        }
    }

    // Handle tool use for both streaming and non-streaming
    async handleToolUse(toolUseData, config = {}) {
        // Generate tool call ID
        const toolCallId = `call_${Date.now()}`;
        
        // Remove any existing loading messages
        this.elements.messagesContainer.find('.loading-message').remove();
        
        // Add a loading message while processing
        const loadingMessage = this.createMessageElement(
            'assistant',
            'Searching...'
        );
        this.elements.messagesContainer.append(loadingMessage);
        this.scrollToBottom();

        let toolResult;
        try {
            // Execute tool call silently
            toolResult = await this.executeToolCall(toolUseData.name, toolUseData.input);
        } catch (error) {
            // If tool execution fails, create an error result
            toolResult = {
                error: true,
                message: error.message || 'Tool execution failed'
            };
        }

        // Remove loading message
        loadingMessage.remove();

        // Add tool call and result to message history
        await this.addToolHistoryAndGetResponse(
            toolUseData.name, 
            toolUseData.input, 
            toolResult.error ? { error: toolResult.message } : toolResult, 
            toolCallId, 
            config
        );
    }

    // Add tool history and get model response
    async addToolHistoryAndGetResponse(toolName, toolInput, toolResult, toolCallId, config = {}) {
        const modelId = this.config.default_model;
        const isMistral = modelId.includes('mistral.mistral');
        const isClaude = modelId.includes('anthropic.claude');
        const isNova = modelId.includes('amazon.nova');

        // Add tool call and result to history based on model type
        if (isClaude) {
            // Format for Claude
            this.messageHistory.push(
                {
                    role: 'assistant',
                    content: [{
                        type: 'tool_use',
                        id: toolCallId,
                        name: toolName,
                        input: typeof toolInput === 'string' ? 
                            JSON.parse(toolInput) : toolInput
                    }]
                },
                {
                    role: 'user',
                    content: [{
                        type: 'tool_result',
                        tool_use_id: toolCallId,
                        content: toolResult
                    }]
                }
            );
        } else if (isMistral) {
            // Format for Mistral
            this.messageHistory.push(
                {
                    role: 'assistant',
                    content: '',
                    tool_calls: [{
                        id: toolCallId,
                        function: {
                            name: toolName,
                            arguments: typeof toolInput === 'string' ? 
                                toolInput : JSON.stringify(toolInput)
                        }
                    }]
                },
                {
                    role: 'tool',
                    tool_call_id: toolCallId,
                    content: typeof toolResult === 'string' ? 
                        toolResult : JSON.stringify(toolResult)
                }
            );
        } else if (isNova) {
            // Format for Nova
            this.messageHistory.push(
                {
                    role: 'assistant',
                    content: [{
                        toolUse: {
                            toolUseId: toolCallId,
                            name: toolName,
                            input: typeof toolInput === 'string' ? 
                                JSON.parse(toolInput) : toolInput
                        }
                    }]
                },
                {
                    role: 'user',
                    content: [{
                        toolResult: {
                            toolUseId: toolCallId,
                            content: [{
                                json: {
                                    content: toolResult
                                }
                            }]
                        }
                    }]
                }
            );
        }

        // Get model's response to tool result
        const requestBody = BedrockAPI.formatRequestBody(
            this.messageHistory,
            {
                model: this.config.default_model,
                temperature: Number(this.config.default_temperature || 0.7),
                max_tokens: config.max_tokens || 2000,
                top_p: config.top_p || 0.9,
                anthropic_version: config.anthropic_version || "bedrock-2023-05-31"
            },
            this.selectedTools
        );

        // Remove any existing loading messages
        this.elements.messagesContainer.find('.loading-message').remove();
        
        // Add a loading message while waiting for response
        const loadingMessage = this.createMessageElement(
            'assistant',
            'Thinking...'
        );
        this.elements.messagesContainer.append(loadingMessage);
        this.scrollToBottom();

        try {
            // Send non-streaming request
            const toolResponse = await this.sendNonStreamingRequest(requestBody);
            
            // Remove loading message before handling response
            loadingMessage.remove();
            
            await this.handleResponse(toolResponse, false);
        } catch (error) {
            // Remove loading message on error
            loadingMessage.remove();
            throw error;
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
        // Remove any existing loading messages
        this.elements.messagesContainer.find('.loading-message').remove();
        
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

    // Execute tool call
    async executeToolCall(toolName, args) {
        console.log('[BedrockChatManager] Executing tool call:', { toolName, args });
        
        // Remove any existing loading messages before executing tool
        this.elements.messagesContainer.find('.loading-message').remove();
        
        // Get tool configuration
        const normalizedToolName = toolName.toLowerCase().replace(/[^a-z0-9]/g, '_');
        const toolConfig = window.wpbedrock_tools?.tools?.find(tool => 
            tool.info.title.toLowerCase().replace(/[^a-z0-9]/g, '_') === normalizedToolName
        );

        // Format args based on tool type
        if (normalizedToolName === 'arxiv_search') {
            // Format arXiv search query
            const query = typeof args === 'string' ? args : 
                args.search_query || args.q || args.query || args.text || 
                (typeof args === 'object' && Object.values(args)[0]) || 'GPT';
            
            // Add default parameters for arXiv search
            args = {
                search_query: `ti:"${query}" OR abs:"${query}"`,
                sortBy: 'lastUpdatedDate',
                sortOrder: 'descending',
                max_results: 5
            };
        }

        if (!toolConfig) {
            throw new Error(`Tool ${toolName} not found in configuration`);
        }

        if (!toolConfig.paths || Object.keys(toolConfig.paths).length === 0) {
            throw new Error(`Tool ${toolName} has no valid paths configured`);
        }

        if (!toolConfig.servers || !toolConfig.servers[0]?.url) {
            throw new Error(`Tool ${toolName} has no valid server URL configured`);
        }

        // Get tool endpoint info
        const pathKeys = Object.keys(toolConfig.paths);
        if (pathKeys.length === 0) {
            throw new Error(`Tool ${toolName} has no paths configured`);
        }
        const pathKey = pathKeys[0];
        
        const pathConfig = toolConfig.paths[pathKey];
        if (!pathConfig || typeof pathConfig !== 'object') {
            throw new Error(`Invalid path configuration for tool ${toolName}`);
        }
        
        const methodKeys = Object.keys(pathConfig);
        if (methodKeys.length === 0) {
            throw new Error(`No HTTP methods configured for path ${pathKey} in tool ${toolName}`);
        }
        const methodKey = methodKeys[0];
        
        const serverUrl = toolConfig.servers[0]?.url;
        if (!serverUrl) {
            throw new Error(`No server URL configured for tool ${toolName}`);
        }

        // Build request URL and parameters
        const url = (serverUrl + pathKey).replace(/\/+$/, ''); // Remove trailing slashes
        
        // Ensure args are properly formatted before sending to proxy
        let formattedArgs = {};
        // Convert args from string to object if needed
        const argsObj = typeof args === 'string' ? JSON.parse(args) : args;
        
        // Get operation details
        const operation = toolConfig.paths[pathKey][methodKey];
        
        // Check if tool uses requestBody
        if (operation.requestBody) {
            // For tools with requestBody, ensure arguments match schema
            const schema = operation.requestBody.content['application/json'].schema;
            if (schema.required) {
                // Ensure all required fields are present
                schema.required.forEach(field => {
                    if (!(field in argsObj)) {
                        throw new Error(`Missing required field: ${field}`);
                    }
                });
            }
            // For CodeInterpreter, ensure required fields
            if (normalizedToolName === 'code_interpreter') {
                if (!argsObj.variables) {
                    argsObj.variables = {};
                }
                if (!argsObj.languageType) {
                    argsObj.languageType = 'python';
                }
            }
            formattedArgs = argsObj;
        } else {
            // For tools with parameters, format them according to spec
            const parameters = operation.parameters || [];
            const requiredParams = parameters
                .filter(param => param.required)
                .map(param => param.name);

            // Include required parameters and additional params for specific tools
            Object.entries(argsObj).forEach(([key, value]) => {
                if (requiredParams.includes(key)) {
                    formattedArgs[key] = typeof value === 'string' ? value : JSON.stringify(value);
                }
            });

            // Add additional parameters for DuckDuckGo Lite
            if (normalizedToolName === 'duckduckgo lite') {
                // Only add required parameters as defined in tools.json
                formattedArgs.q = encodeURIComponent(argsObj.q);
                formattedArgs.o = 'json';
                formattedArgs.api = 'd.js';
                formattedArgs.s = '0';
                formattedArgs.kl = 'wt-wt'; // Worldwide results
            }

            // Ensure all required parameters are present
            const missingParams = requiredParams.filter(param => !(param in formattedArgs));
            if (missingParams.length > 0) {
                throw new Error(`Missing required parameters: ${missingParams.join(', ')}`);
            }
        }

        // Get the HTTP method from tool configuration
        // For GET requests, prefer GET if available
        const requestMethod = pathConfig['get'] ? 'GET' : methodKey.toUpperCase();

        // Make request through WordPress proxy
        const response = await this.$.ajax({
            url: this.config.ajaxurl,
            method: 'POST',
            data: {
                action: 'wpbedrock_tool_proxy',
                nonce: this.config.nonce,
                url: url,
                method: requestMethod,
                // For POST requests where parameters are defined as query params, pass them as query string
                params: formattedArgs,
                // Add flag to indicate parameters should be in query string for this POST request
                queryParams: methodKey === 'post' && operation.parameters?.length > 0 && operation.parameters.every(p => p.in === 'query')
            },
            timeout: 30000 // 30 second timeout
        });

        if (!response || !response.success) {
            throw new Error(response?.data || 'Tool execution failed');
        }

        // Handle empty results
        if (response.data === undefined || response.data === null) {
            return {
                message: 'No results found. Please try a different search query.',
                status: 'empty'
            };
        }

        // Parse response data
        let result = response.data;
        
        // For DuckDuckGo Lite, extract search results from HTML response
        if (normalizedToolName === 'duckduckgo lite' && typeof result === 'string') {
            try {
                // Try parsing as JSON first
                result = JSON.parse(result);
            } catch (e) {
                // If not JSON, it's likely HTML - create a temporary div to parse it
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = result;
                
                // Extract search results
                const searchResults = Array.from(tempDiv.querySelectorAll('.result')).map(result => {
                    const titleElem = result.querySelector('.result__title');
                    const snippetElem = result.querySelector('.result__snippet');
                    const urlElem = result.querySelector('.result__url');
                    const timeElem = result.querySelector('.result__timestamp');
                    
                    return {
                        title: titleElem?.textContent?.trim() || '',
                        snippet: snippetElem?.textContent?.trim() || '',
                        url: urlElem?.textContent?.trim() || '',
                        time: timeElem?.textContent?.trim() || ''
                    };
                });
                
                // Filter out empty results and limit to top 5 most relevant
                const filteredResults = searchResults
                    .filter(r => r.title && r.snippet)
                    .slice(0, 5);
                
                result = {
                    results: filteredResults,
                    type: 'search_results',
                    query: decodeURIComponent(argsObj.q)
                };
            }
        } else if (typeof result === 'string') {
            try {
                result = JSON.parse(result);
            } catch (e) {
                result = { content: result };
            }
        }

        return result;
    }

    // Extract tool info from formatted tool call
    extractToolInfo(formattedToolCall) {
        // Remove any existing loading messages before extracting tool info
        this.elements.messagesContainer.find('.loading-message').remove();
        
        if (!formattedToolCall) return null;

        // Handle Claude format
        if (formattedToolCall.content?.[0]?.type === 'tool_use') {
            return {
                id: formattedToolCall.content[0].id,
                name: formattedToolCall.content[0].name,
                input: formattedToolCall.content[0].input
            };
        }

        // Handle Mistral format
        if (formattedToolCall.tool_calls?.[0]) {
            return {
                id: formattedToolCall.tool_calls[0].id,
                name: formattedToolCall.tool_calls[0].function.name,
                input: JSON.parse(formattedToolCall.tool_calls[0].function.arguments)
            };
        }

        // Handle Nova format
        if (formattedToolCall.content?.[0]?.toolUse) {
            const toolUse = formattedToolCall.content[0].toolUse;
            return {
                id: toolUse.toolUseId,
                name: toolUse.name,
                input: toolUse.input
            };
        }

        return null;
    }

    // Toggle tool selection
    toggleTool(toolDefinition) {
        // Remove any existing loading messages before toggling tool
        this.elements.messagesContainer.find('.loading-message').remove();
        
        // Get model type from config
        const modelId = this.config.default_model;
        const isMistral = modelId.includes('mistral.mistral');
        const isClaude = modelId.includes('anthropic.claude');
        const isNova = modelId.includes('amazon.nova');

        let normalizedTool;
        const toolName = toolDefinition.info.title.toLowerCase().replace(/[^a-z0-9]/g, '_');
        const parameters = this.buildToolParameters(toolDefinition);

        if (isClaude) {
            // Claude format - only name and input_schema are allowed
            normalizedTool = {
                name: toolName,
                input_schema: parameters
            };
        } else if (isMistral) {
            // Mistral format
            normalizedTool = {
                type: 'function',
                function: {
                    name: toolName,
                    description: toolDefinition.info.description || '',
                    parameters: parameters
                }
            };
        } else if (isNova) {
            // Nova format - ensure proper structure
            normalizedTool = {
                toolSpec: {
                    name: toolName,
                    description: toolDefinition.info.description || '',
                    inputSchema: {
                        json: {
                            type: "object",
                            properties: parameters.properties || {},
                            required: parameters.required || []
                        }
                    }
                }
            };
        } else {
            // Default format
            normalizedTool = {
                type: 'function',
                name: toolName,
                description: toolDefinition.info.description || '',
                parameters: parameters
            };
        }
        
        // For Nova, we need to find by toolSpec.name
        const index = isNova ? 
            this.selectedTools.findIndex(t => t.toolSpec?.name === normalizedTool.toolSpec?.name) :
            this.selectedTools.findIndex(t => t.name === normalizedTool.name);

        if (index === -1) {
            this.selectedTools.push(normalizedTool);
        } else {
            this.selectedTools.splice(index, 1);
        }

        // Log selected tools for debugging
        console.log('[BedrockChatManager] Selected tools:', this.selectedTools);
    }

    buildToolParameters(toolDefinition) {
        const paths = toolDefinition.paths || {};
        const pathKeys = Object.keys(paths);
        if (!pathKeys.length) return { type: 'object', properties: {}, required: [] };

        const firstPath = pathKeys[0];
        const pathConfig = paths[firstPath];
        if (!pathConfig || typeof pathConfig !== 'object') {
            return { type: 'object', properties: {}, required: [] };
        }

        const methodKeys = Object.keys(pathConfig);
        if (!methodKeys.length) return { type: 'object', properties: {}, required: [] };

        const firstMethod = methodKeys[0];
        const operation = pathConfig[firstMethod];

        // Build parameters schema based on tool type
        if (operation.requestBody) {
            // For tools with requestBody (like CodeInterpreter)
            const schema = operation.requestBody.content['application/json'].schema;
            return {
                type: 'object',
                properties: schema.properties || {},
                required: schema.required || []
            };
        } else if (operation.parameters) {
            // For tools with query parameters (like DuckDuckGo)
            const properties = {};
            const required = [];
            
            operation.parameters.forEach(param => {
                properties[param.name] = {
                    type: param.schema?.type || 'string',
                    description: param.description || ''
                };
                if (param.required) {
                    required.push(param.name);
                }
            });

            return {
                type: 'object',
                properties: properties,
                required: required
            };
        }

        return {
            type: 'object',
            properties: {},
            required: []
        };
    }
}

// Export the chat manager class
if (typeof window !== 'undefined' && !window.BedrockChatManager) {
    window.BedrockChatManager = BedrockChatManager;
}
