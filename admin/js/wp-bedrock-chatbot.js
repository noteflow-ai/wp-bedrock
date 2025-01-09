function initChatbot() {
    // Check for all required dependencies
    const requiredDeps = {
        'jQuery': () => typeof jQuery !== 'undefined',
        'wpbedrock_chat': () => typeof wpbedrock_chat !== 'undefined',
        'markdownit': () => typeof window.markdownit !== 'undefined',
        'hljs': () => typeof window.hljs !== 'undefined',
        'jQuery UI Dialog': () => typeof jQuery !== 'undefined' && typeof jQuery.fn.dialog !== 'undefined'
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
            console.log(`[WP Bedrock] Waiting for libraries (attempt ${window.wpBedrockInitAttempts}/${MAX_ATTEMPTS}):`, missing.join(', '));
            setTimeout(initChatbot, 100);
            return;
        } else {
            console.error('[WP Bedrock] Failed to load required libraries after 10 seconds:', missing.join(', '));
            const container = document.querySelector('.chat-container');
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

console.log('[WP Bedrock] All dependencies loaded, initializing chatbot...');
const $ = jQuery;

// Chat state
let isProcessing = false;
let currentStreamingMessage = null;
let messageHistory = [];
let currentEventSource = null;
let isFullscreen = false;
let chunks = [];
let pendingChunk = null;
let remainText = '';
let runTools = [];
let toolIndex = -1;
let selectedTools = []; // Moved to outer scope

// DOM Elements
const elements = {
    chatContainer: $('.chat-container'),
    messagesContainer: $('#wpaicg-chat-messages'),
    messageInput: $('#wpaicg-chat-message'),
    sendButton: $('#wpaicg-send-message'),
    stopButton: $('#wpaicg-stop-message'),
    imageUpload: $('#wpaicg-image-upload'),
    imageTrigger: $('#wpaicg-image-trigger'),
    imagePreview: $('#wpaicg-image-preview'),
    previewImage: $('#wpaicg-preview-image'),
    removeImageButton: $('#wpaicg-remove-image'),
    clearChatButton: $('#clear-chat'),
    refreshChatButton: $('#refresh-chat'),
    exportChatButton: $('#export-chat'),
    shareChatButton: $('#share-chat'),
    fullscreenButton: $('#fullscreen-chat'),
    settingsTrigger: $('#wpaicg-settings-trigger'),
    promptTrigger: $('#wpaicg-prompt-trigger'),
    maskTrigger: $('#wpaicg-mask-trigger'),
    voiceTrigger: $('#wpaicg-voice-trigger'),
    gridTrigger: $('#wpaicg-grid-trigger'),
    layoutTrigger: $('#wpaicg-layout-trigger'),
    messageCountDisplay: $('.message-count')
};

// Tool handling
async function executeTool(toolCall) {
    try {
        const response = await fetch(wpbedrock_chat.ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'wpbedrock_tool',
                nonce: wpbedrock_chat.nonce,
                tool: toolCall.function.name,
                parameters: toolCall.function.arguments
            })
        });

        if (!response.ok) throw new Error('Network response was not ok');
        const result = await response.json();

        if (!result.success) {
            throw new Error(result.data);
        }

        return {
            tool_call_id: toolCall.id,
            role: 'tool',
            name: toolCall.function.name,
            content: JSON.stringify(result.data)
        };
    } catch (error) {
        console.error('[WP Bedrock] Tool execution failed:', error);
        return {
            tool_call_id: toolCall.id,
            role: 'tool',
            name: toolCall.function.name,
            content: `Error: ${error.message}`
        };
    }
}

// Message handling
function updateMessageCount() {
    const count = messageHistory.length;
    elements.messageCountDisplay.text(`${count} message${count !== 1 ? 's' : ''}`);
}

// Message creation helpers
function createMessageElement(content, isUser = false, imageUrl = null) {
    const messageDiv = $('<div>')
        .addClass('chat-message')
        .addClass(isUser ? 'user' : 'ai');

    const containerDiv = $('<div>')
        .addClass('chat-message-container');

    const headerDiv = $('<div>')
        .addClass('chat-message-header');

    if (!isUser) {
        // Create and append AI avatar with error handling
        const avatarImg = $('<img>')
            .attr({
                src: wpbedrock_chat.ai_avatar || `${wpbedrock_chat.plugin_url}images/ai-avatar.svg`,
                alt: 'AI',
                width: 35,
                height: 35
            })
            .css('border-radius', '50%')
            .on('error', function() {
                // If image fails to load, replace with a fallback
                $(this).replaceWith(
                    $('<div>')
                        .css({
                            width: '35px',
                            height: '35px',
                            borderRadius: '50%',
                            backgroundColor: '#2271b1',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            color: '#fff',
                            fontSize: '16px'
                        })
                        .text('AI')
                );
            });
        
        headerDiv.append(avatarImg);
    }

    const contentDiv = $('<div>')
        .addClass('message-content');

    if (imageUrl) {
        contentDiv.append($('<img>').attr('src', imageUrl).addClass('message-image'));
    }

    containerDiv.append(headerDiv, contentDiv);
    messageDiv.append(containerDiv);

    // Set initial content
    updateMessageContent(contentDiv, content, !isUser);

    return messageDiv;
}

function updateMessageContent(contentDiv, content, processAsMarkdown = false) {
    if (processAsMarkdown && typeof content === 'string') {
        if (content.includes('```') || content.includes('**') || content.includes('__')) {
            // Only process markdown if the content contains markdown syntax
            contentDiv.html(processMarkdown(content));
            // Initialize syntax highlighting for code blocks
            contentDiv.find('pre code').each(function(i, block) {
                window.hljs.highlightElement(block);
            });
        } else {
            // For plain text (like Chinese), just set it directly
            contentDiv.text(content);
        }
    } else {
        contentDiv.text(content);
    }
}

function addMessage(content, isUser = false, imageUrl = null) {
    const messageDiv = createMessageElement(content, isUser, imageUrl);
    
    if (!isUser) {
        currentStreamingMessage = messageDiv.find('.message-content');
        if (content && typeof content === 'string') {
            updateMessageContent(currentStreamingMessage, content, true);
        }
    }
    
    elements.messagesContainer.append(messageDiv);
    scrollToBottom();

    // Add message to history
    messageHistory.push({
        role: isUser ? 'user' : 'assistant',
        content: content
    });

    updateMessageCount();
    return messageDiv;
}

function scrollToBottom() {
    elements.messagesContainer.scrollTop(elements.messagesContainer[0].scrollHeight);
}

// Stream Processing
async function processMessage(data) {
    if (!data) return;

    try {
        // Handle Claude responses
        if (wpbedrock_chat.default_model.includes('anthropic.claude')) {
            // Parse the bytes field if present
            if (data.bytes) {
                try {
                    const decoded = JSON.parse(atob(data.bytes));
                    data = decoded;
                } catch (e) {
                    console.warn('[WP Bedrock] Failed to parse bytes:', e);
                    return;
                }
            }

            if (data.type === 'message_start') {
                remainText = '';
                return;
            }
            if (data.type === 'content_block_start') {
                if (data.content_block.type === 'text') {
                    remainText = '';
                }
                return;
            }
            if (data.type === 'content_block_delta') {
                if (data.delta.type === 'text_delta') {
                    remainText += data.delta.text;
                    if (currentStreamingMessage) {
                        updateMessageContent(currentStreamingMessage, remainText, true);
                        scrollToBottom();
                    }
                }
                return;
            }
            if (data.type === 'tool_use') {
                toolIndex += 1;
                runTools.push({
                    id: data.id,
                    type: 'function',
                    function: {
                        name: data.name,
                        arguments: JSON.stringify(data.input)
                    }
                });
                return;
            }
        } else if (wpbedrock_chat.default_model.includes('mistral.mistral')) {
            // Handle Mistral tool calls
            if (data.tool_calls) {
                toolIndex += 1;
                runTools.push(...data.tool_calls.map(tool => ({
                    id: tool.id,
                    type: 'function',
                    function: {
                        name: tool.function.name,
                        arguments: tool.function.arguments
                    }
                })));
                return;
            }
        } else if (wpbedrock_chat.default_model.includes('amazon.nova')) {
            // Handle Nova tool calls
            if (data.contentBlockStart?.start?.toolUse) {
                const toolUse = data.contentBlockStart.start.toolUse;
                toolIndex += 1;
                runTools.push({
                    id: toolUse.toolUseId,
                    type: 'function',
                    function: {
                        name: toolUse.name || '',
                        arguments: JSON.stringify(toolUse.input || {})
                    }
                });
                return;
            }

            // Handle Nova tool input
            if (data.contentBlockDelta?.delta?.toolUse?.input) {
                if (runTools[toolIndex]) {
                    runTools[toolIndex].function.arguments = JSON.stringify(data.contentBlockDelta.delta.toolUse.input);
                }
                return;
            }
        }

        // Execute tool when arguments are complete
        if (runTools[toolIndex] && runTools[toolIndex].function.arguments) {
            const toolResult = await executeTool(runTools[toolIndex]);
            addMessage(`Tool Result (${toolResult.name}): ${toolResult.content}`, false);
            return;
        }

        // Handle text content
        if (data.output?.message?.content?.[0]?.text) {
            remainText += data.output.message.content[0].text;
                if (currentStreamingMessage) {
                    updateMessageContent(currentStreamingMessage, remainText, true);
                    scrollToBottom();
                }
            return;
        }

        // Handle text delta
        if (data.contentBlockDelta?.delta?.text) {
            remainText += data.contentBlockDelta.delta.text;
                if (currentStreamingMessage) {
                    updateMessageContent(currentStreamingMessage, remainText, true);
                    scrollToBottom();
                }
            return;
        }

        // Handle various response formats
        let newText = '';
        if (data.delta?.text) {
            newText = data.delta.text;
        } else if (data.choices?.[0]?.message?.content) {
            newText = data.choices[0].message.content;
        } else if (data.content?.[0]?.text) {
            newText = data.content[0].text;
        } else if (data.generation) {
            newText = data.generation;
        } else if (data.outputText) {
            newText = data.outputText;
        } else if (data.response) {
            newText = data.response;
        } else if (data.output) {
            newText = data.output;
        }

        if (newText) {
            remainText += newText;
                if (currentStreamingMessage) {
                    updateMessageContent(currentStreamingMessage, remainText, true);
                    scrollToBottom();
                }
        }
    } catch (e) {
        console.warn('[WP Bedrock] Failed to process message:', e);
    }
}

    async function processChunk(chunk) {
        try {
            const decoder = new TextDecoder('utf-8');
            const text = decoder.decode(chunk);
            console.log('[WP Bedrock] Received chunk:', text);
            
            // Parse the SSE data lines
            const lines = text.split('\n');
            for (const line of lines) {
                if (!line.startsWith('data: ')) {
                    console.log('[WP Bedrock] Skipping non-data line:', line);
                    continue;
                }
                
                try {
                    // Parse the JSON data
                    const jsonStr = line.slice(6); // Remove 'data: ' prefix
                    console.log('[WP Bedrock] Processing JSON data:', jsonStr);
                    const data = JSON.parse(jsonStr);

                    // Handle Claude format (bytes field)
                    if (data.bytes) {
                        console.log('[WP Bedrock] Processing Claude bytes data');
                        const innerJson = JSON.parse(data.bytes);
                        console.log('[WP Bedrock] Parsed Claude message:', innerJson);
                        
                        console.log('[WP Bedrock] Processing message type:', innerJson.type);
                        switch (innerJson.type) {
                            case 'message_start': {
                                console.log('[WP Bedrock] Starting new message');
                                remainText = '';
                                if (!currentStreamingMessage) {
                                    addMessage('', false);
                                }
                                break;
                            }
                                
                            case 'content_block_start': {
                                if (innerJson.content_block?.type === 'text') {
                                    remainText = remainText || '';
                                }
                                break;
                            }
                                
                            case 'content_block_delta': {
                                if (innerJson.delta?.type === 'text_delta' && innerJson.delta?.text) {
                                    console.log('[WP Bedrock] Adding text delta:', innerJson.delta.text);
                                    remainText += innerJson.delta.text;
                                    if (!currentStreamingMessage) {
                                        console.log('[WP Bedrock] Creating new message for delta');
                                        addMessage('', false);
                                    }
                                    console.log('[WP Bedrock] Updating message content:', remainText);
                                    updateMessageContent(currentStreamingMessage, remainText, true);
                                    scrollToBottom();
                                }
                                break;
                            }
                                
                            case 'tool_use': {
                                toolIndex++;
                                runTools.push({
                                    id: innerJson.id,
                                    type: 'function',
                                    function: {
                                        name: innerJson.name,
                                        arguments: JSON.stringify(innerJson.input)
                                    }
                                });
                                break;
                            }
                                
                            case 'message_stop': {
                                if (currentStreamingMessage && remainText) {
                                    messageHistory[messageHistory.length - 1].content = remainText;
                                }
                                break;
                            }
                        }
                        continue;
                    }

                    // Handle non-Claude models
                    
                    // Handle message content
                    if (data.output?.message?.content?.[0]?.text) {
                        remainText += data.output.message.content[0].text;
                    } else if (data.contentBlockDelta?.delta?.text) {
                        remainText += data.contentBlockDelta.delta.text;
                    } else if (data.delta?.text) {
                        remainText += data.delta.text;
                    } else if (data.choices?.[0]?.message?.content) {
                        remainText += data.choices[0].message.content;
                    }

                    // Update UI if we have new content
                    if (remainText && currentStreamingMessage) {
                        updateMessageContent(currentStreamingMessage, remainText, true);
                        scrollToBottom();
                    }

                    // Handle tool calls
                    if (data.tool_calls) { // Mistral format
                        toolIndex++;
                        runTools.push(...data.tool_calls.map(tool => ({
                            id: tool.id,
                            type: 'function',
                            function: {
                                name: tool.function.name,
                                arguments: tool.function.arguments
                            }
                        })));
                    } else if (data.contentBlockStart?.start?.toolUse) { // Nova format
                        const toolUse = data.contentBlockStart.start.toolUse;
                        toolIndex++;
                        runTools.push({
                            id: toolUse.toolUseId,
                            type: 'function',
                            function: {
                                name: toolUse.name || '',
                                arguments: JSON.stringify(toolUse.input || {})
                            }
                        });
                    }

                    // Handle stream completion
                    if (data.done) {
                        console.log('[WP Bedrock] Stream completed, final message:', remainText);
                        if (!currentStreamingMessage) {
                            console.log('[WP Bedrock] Creating new message for completion');
                            addMessage('', false);
                        }
                        if (currentStreamingMessage && remainText) {
                            console.log('[WP Bedrock] Updating final message content');
                            messageHistory[messageHistory.length - 1].content = remainText;
                            updateMessageContent(currentStreamingMessage, remainText, true);
                            scrollToBottom();
                        }
                        return;
                    }

                    // Execute tool if arguments are complete
                    if (runTools[toolIndex] && runTools[toolIndex].function.arguments) {
                        const toolResult = await executeTool(runTools[toolIndex]);
                        addMessage(`Tool Result (${toolResult.name}): ${toolResult.content}`, false);
                    }

                } catch (e) {
                    console.warn('[WP Bedrock] Failed to process SSE message:', e);
                }
            }
        } catch (e) {
            console.warn('[WP Bedrock] Failed to process chunk:', e);
        }
    }

    // UI Feedback
    function showTypingIndicator() {
        const indicator = $('<div>')
            .addClass('typing-indicator')
            .append($('<span>'))
            .append($('<span>'))
            .append($('<span>'));
        elements.messagesContainer.append(indicator);
        scrollToBottom();
    }

    function removeTypingIndicator() {
        $('.typing-indicator').remove();
    }

    function setProcessingState(processing) {
        isProcessing = processing;
        elements.sendButton.prop('disabled', processing);
        elements.stopButton.toggle(processing);
        elements.sendButton.toggle(!processing);
    }

    // Chat Management
    function clearChat() {
        if (confirm('Are you sure you want to clear the chat history?')) {
            elements.messagesContainer.empty();
            messageHistory = [];
            addMessage(wpbedrock_chat.initial_message || 'Hello! How can I assist you today?', false);
            updateMessageCount();
        }
    }

    function refreshChat() {
        location.reload();
    }

    async function copyChat() {
        const chatContent = messageHistory.map(msg => {
            const role = msg.role === 'assistant' ? 'AI' : 'User';
            const content = Array.isArray(msg.content) 
                ? msg.content.map(c => c.text || '[Image]').join('\n')
                : msg.content;
            return `${role}: ${content}`;
        }).join('\n\n');

        try {
            await navigator.clipboard.writeText(chatContent);
            const button = elements.exportChatButton;
            button.addClass('button-primary');
            setTimeout(() => button.removeClass('button-primary'), 1000);
        } catch (err) {
            console.error('[WP Bedrock] Failed to copy chat:', err);
            alert('Failed to copy chat to clipboard');
        }
    }

    function shareChat() {
        const chatContent = encodeURIComponent(
            messageHistory.map(msg => {
                const role = msg.role === 'assistant' ? 'AI' : 'User';
                const content = Array.isArray(msg.content) 
                    ? msg.content.map(c => c.text || '[Image]').join('\n')
                    : msg.content;
                return `${role}: ${content}`;
            }).join('\n\n')
        );

        const shareUrl = `https://twitter.com/intent/tweet?text=${chatContent}`;
        window.open(shareUrl, '_blank');
    }

    function toggleFullscreen() {
        isFullscreen = !isFullscreen;
        elements.chatContainer.toggleClass('fullscreen');
        elements.fullscreenButton.find('.dashicons')
            .toggleClass('dashicons-fullscreen dashicons-fullscreen-exit');
        
        if (isFullscreen) {
            $('body').css('overflow', 'hidden');
        } else {
            $('body').css('overflow', '');
        }
    }

    // Layout Management
    function toggleLayout() {
        elements.chatContainer.toggleClass('wide-layout');
        elements.layoutTrigger.toggleClass('active');
    }

    // Chat API
    // Add typing animation state
    let typingQueue = [];
    let isTyping = false;
    let typingTimeout = null;

    function updateStreamingMessage(text) {
        if (!currentStreamingMessage) return;
        console.log('[WP Bedrock] Received chunk to type:', text);

        // Split text into characters and add to queue
        const chars = Array.from(text);
        typingQueue.push(...chars);

        // Start typing if not already typing
        if (!isTyping) {
            console.log('[WP Bedrock] Starting to type immediately');
            typeNextChar();
        }
    }

    function typeNextChar() {
        if (typingQueue.length === 0) {
            isTyping = false;
            return;
        }

        isTyping = true;
        const char = typingQueue.shift();
        if (currentStreamingMessage) {
            updateMessageContent(currentStreamingMessage, currentStreamingMessage.text() + char, true);
            scrollToBottom();
        }

        // Schedule next character
        typingTimeout = setTimeout(typeNextChar, 30);
    }

    async function setupEventSource(url) {
        // Close any existing EventSource
        if (currentEventSource) {
            currentEventSource.close();
            currentEventSource = null;
        }

    // For non-streaming mode, use fetch instead of EventSource
    if (!wpbedrock_chat.enable_stream) {
        try {
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const data = await response.json();
            console.log('[WP Bedrock] Non-streaming response:', data);

            // Create message container if needed
            if (!currentStreamingMessage) {
                addMessage('', false);
            }

            // Handle WordPress AJAX response format first
            let responseText = '';
            if (data.success && data.data) {
                responseText = data.data;
            } else if (data.response) {
                responseText = data.response;
            } else if (data.output?.message?.content?.[0]?.text) {
                responseText = data.output.message.content[0].text;
            } else if (data.content?.[0]?.text) {
                responseText = data.content[0].text;
            } else if (data.choices?.[0]?.message?.content) {
                responseText = data.choices[0].message.content;
            } else if (data.generation) {
                responseText = data.generation;
            } else if (data.outputText) {
                responseText = data.outputText;
            } else if (data.output) {
                responseText = data.output;
            }

            if (responseText) {
                updateMessageContent(currentStreamingMessage, responseText, true);
                scrollToBottom();
                messageHistory[messageHistory.length - 1].content = responseText;
            } else {
                throw new Error('No valid response content found');
            }

            setProcessingState(false);
            return;
        } catch (error) {
            console.error('[WP Bedrock] Error in non-streaming mode:', error);
            handleStreamError(null, error.message);
            return;
        }
    }

        // Streaming mode
        let retryCount = 0;
        const MAX_RETRIES = 3;
        const RETRY_DELAY = 1000;

        function createEventSource() {
            const eventSource = new EventSource(url);
            currentEventSource = eventSource;
            console.log('[WP Bedrock] Creating new EventSource connection...');

            eventSource.onopen = function() {
                console.log('[WP Bedrock] Stream connection opened successfully');
                retryCount = 0;
                removeTypingIndicator();
                
                // Create initial empty message if none exists
                if (!currentStreamingMessage) {
                    addMessage('', false);
                }
            };

            eventSource.onmessage = async function(e) {
                try {
                    console.log('[WP Bedrock] Stream message received:', e.data);
                    const data = JSON.parse(e.data);

                    // Handle stream completion
                    if (data.done) {
                        console.log('[WP Bedrock] Stream completed successfully');
                        eventSource.close();
                        currentEventSource = null;
                        setProcessingState(false);
                        return;
                    }

                    // Handle errors
                    if (data.error) {
                        console.error('[WP Bedrock] Stream error received:', data.error);
                        handleStreamError(eventSource, 'Server error: ' + data.error);
                        return;
                    }

                    // Handle Claude format
                    if (data.bytes) {
                        try {
                            const decoded = JSON.parse(atob(data.bytes));
                            
                            // Create message container if needed
                            if (!currentStreamingMessage) {
                                addMessage('', false);
                            }

                            // Process Claude message types
                            switch (decoded.type) {
                                case 'message_start':
                                    remainText = '';
                                    break;
                                    
                                case 'content_block_start':
                                    if (decoded.content_block?.type === 'text') {
                                        remainText = remainText || '';
                                    }
                                    break;
                                    
                                case 'content_block_delta':
                                    if (decoded.delta?.type === 'text_delta' && decoded.delta?.text) {
                                        remainText += decoded.delta.text;
                                        updateMessageContent(currentStreamingMessage, remainText, true);
                                        scrollToBottom();
                                    }
                                    break;
                                    
                                case 'message_stop':
                                    if (currentStreamingMessage && remainText) {
                                        messageHistory[messageHistory.length - 1].content = remainText;
                                    }
                                    break;
                            }
                        } catch (e) {
                            console.warn('[WP Bedrock] Failed to parse bytes:', e);
                        }
                        return;
                    }

                    // Handle other model formats
                    let newText = '';
                    
                    if (data.output?.message?.content?.[0]?.text) {
                        newText = data.output.message.content[0].text;
                    } else if (data.contentBlockDelta?.delta?.text) {
                        newText = data.contentBlockDelta.delta.text;
                    } else if (data.delta?.text) {
                        newText = data.delta.text;
                    } else if (data.choices?.[0]?.message?.content) {
                        newText = data.choices[0].message.content;
                    } else if (data.content?.[0]?.text) {
                        newText = data.content[0].text;
                    }

                    if (newText) {
                        if (!currentStreamingMessage) {
                            addMessage('', false);
                        }
                        remainText += newText;
                        updateMessageContent(currentStreamingMessage, remainText, true);
                        scrollToBottom();
                    }

                } catch (error) {
                    console.error('[WP Bedrock] Error parsing stream message:', error);
                    handleStreamError(eventSource, 'Invalid response format');
                }
            };

            eventSource.onerror = function(e) {
                console.error('[WP Bedrock] Stream connection error:', e);
                
                if (retryCount < MAX_RETRIES) {
                    retryCount++;
                    console.log(`[WP Bedrock] Connection lost. Retrying (${retryCount}/${MAX_RETRIES}) in ${RETRY_DELAY}ms...`);
                    
                    eventSource.close();
                    currentEventSource = null;
                    setTimeout(() => {
                        console.log('[WP Bedrock] Attempting to reconnect...');
                        createEventSource();
                    }, RETRY_DELAY);
                } else {
                    handleStreamError(eventSource, 'Connection failed after multiple retries');
                }
            };

            return eventSource;
        }

        function handleStreamError(eventSource, errorMessage) {
            console.error('[WP Bedrock]', errorMessage);
            if (currentStreamingMessage) {
                updateMessageContent(currentStreamingMessage, 'Error: ' + errorMessage, true);
            } else {
                addMessage('Error: ' + errorMessage, false);
            }
            removeTypingIndicator();
            if (eventSource) {
                eventSource.close();
                currentEventSource = null;
            }
            setProcessingState(false);
        }

        return createEventSource();
    }

    async function sendMessage() {
        const message = elements.messageInput.val().trim();
        if (!message || isProcessing) return;

        // Reset typing state
        typingQueue = [];
        isTyping = false;
        if (typingTimeout) {
            clearTimeout(typingTimeout);
            typingTimeout = null;
        }

        addMessage(message, true);
        elements.messageInput.val('');
        elements.imagePreview.hide();
        elements.imageUpload.val('');

        setProcessingState(true);
        
        // Only show typing indicator in streaming mode
        if (wpbedrock_chat.enable_stream) {
            showTypingIndicator();
        }

        const params = new URLSearchParams({
            action: 'wpbedrock_chat_message',
            nonce: wpbedrock_chat.nonce,
            message: message,
            stream: wpbedrock_chat.enable_stream ? '1' : '0'
        });

        if (selectedTools.length > 0) {
            params.append('tools', JSON.stringify(selectedTools.map(tool => {
                if (wpbedrock_chat.default_model.includes('anthropic.claude')) {
                    return {
                        name: tool.function.name,
                        description: tool.function.description,
                        input_schema: tool.function.parameters || {}
                    };
                } else if (wpbedrock_chat.default_model.includes('mistral.mistral')) {
                    return {
                        type: 'function',
                        function: {
                            name: tool.function.name,
                            description: tool.function.description,
                            parameters: tool.function.parameters
                        }
                    };
                } else if (wpbedrock_chat.default_model.includes('amazon.nova')) {
                    return {
                        toolSpec: {
                            name: tool.function.name,
                            description: tool.function.description,
                            inputSchema: {
                                json: {
                                    type: "object",
                                    properties: tool.function.parameters.properties || {},
                                    required: tool.function.parameters.required || []
                                }
                            }
                        }
                    };
                }
                return {
                    name: tool.function.name,
                    description: tool.function.description,
                    input_schema: {
                        type: "object",
                        properties: tool.function.parameters.properties || {},
                        required: tool.function.parameters.required || []
                    }
                };
            })));
        }

        const url = `${wpbedrock_chat.ajaxurl}?${params.toString()}`;
        console.log('[WP Bedrock] Request URL:', url);

        try {
            remainText = '';
            runTools = [];
            toolIndex = -1;

            removeTypingIndicator();
            addMessage('', false); // Create empty message for streaming

            setupEventSource(url);
        } catch (error) {
            console.error('[WP Bedrock] Chat error:', error);
            removeTypingIndicator();
            let errorMessage = 'An error occurred. Please try again.';
            if (error.message.includes('AWS credentials not configured')) {
                errorMessage = 'AWS credentials are not configured. Please go to Bedrock AI Agent > Settings to configure your AWS credentials.';
            }
            addMessage(errorMessage, false);
            setProcessingState(false);
        }
    }

    // Initialize tools modal
    function initializeToolsModal() {
        const toolsModal = $('#tools-modal');
        
        // Initialize jQuery UI Dialog
        toolsModal.dialog({
            autoOpen: false,
            modal: true,
            width: 600,
            dialogClass: 'tools-dialog'
        });
        
        // Handle tool selection
        $('.tool-item').on('click', function() {
            const $this = $(this);
            const toolDefinition = JSON.parse($this.attr('data-tool-definition'));
            
            // Toggle selection
            $this.toggleClass('selected');
            
            if ($this.hasClass('selected')) {
                if (!selectedTools.find(t => t.function.name === toolDefinition.function.name)) {
                    selectedTools.push(toolDefinition);
                }
            } else {
                selectedTools = selectedTools.filter(t => t.function.name !== toolDefinition.function.name);
            }
        });
    }

    // Event Listeners
    function setupEventListeners() {
        // Open modal on grid button click
        $('#wpaicg-grid-trigger').on('click', function() {
            $('#tools-modal').dialog('open');
        });

        elements.sendButton.on('click', sendMessage);
        elements.stopButton.on('click', () => {
            if (currentEventSource) {
                currentEventSource.close();
                currentEventSource = null;
            }
            setProcessingState(false);
        });
        
        elements.imageTrigger.on('click', () => elements.imageUpload.click());
        elements.imageUpload.on('change', function() {
            if (this.files && this.files[0]) {
                const file = this.files[0];
                const reader = new FileReader();
                reader.onload = function(e) {
                    elements.previewImage.attr('src', e.target.result);
                    elements.imagePreview.show();
                };
                reader.readAsDataURL(file);
            }
        });
        
        elements.removeImageButton.on('click', () => {
            elements.imagePreview.hide();
            elements.imageUpload.val('');
        });

        elements.clearChatButton.on('click', clearChat);
        elements.refreshChatButton.on('click', refreshChat);
        elements.exportChatButton.on('click', copyChat);
        elements.shareChatButton.on('click', shareChat);
        elements.fullscreenButton.on('click', toggleFullscreen);
        elements.layoutTrigger.on('click', toggleLayout);

        elements.messageInput
            .on('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            })
            .on('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && isFullscreen) {
                toggleFullscreen();
            }
        });
    }

    // Initialize libraries and setup markdown processing
    let md;

    // Process markdown content
    function processMarkdown(content) {
        try {
            return md.render(content);
        } catch (e) {
            console.warn('[WP Bedrock] Failed to process markdown:', e);
            return content;
        }
    }

    function waitForLibraries() {
        return new Promise((resolve, reject) => {
            let attempts = 0;
            const maxAttempts = 200; // 20 seconds total
            const interval = 100; // Check every 100ms

            function checkLibraries() {
                console.log('Checking libraries...');
                console.log('jQuery UI:', typeof $.fn.dialog !== 'undefined');
                console.log('markdownit:', typeof window.markdownit !== 'undefined');
                console.log('hljs:', typeof window.hljs !== 'undefined');

                // First check for jQuery UI as it's loaded by WordPress
                if (typeof $.fn.dialog === 'undefined') {
                    if (attempts < maxAttempts) {
                        attempts++;
                        setTimeout(checkLibraries, interval);
                    } else {
                        reject(new Error('jQuery UI Dialog failed to load'));
                    }
                    return;
                }

                // Then check for markdown-it
                if (typeof window.markdownit === 'undefined') {
                    if (attempts < maxAttempts) {
                        attempts++;
                        setTimeout(checkLibraries, interval);
                    } else {
                        reject(new Error('markdown-it failed to load'));
                    }
                    return;
                }

                // Finally check for highlight.js
                if (typeof window.hljs === 'undefined') {
                    if (attempts < maxAttempts) {
                        attempts++;
                        setTimeout(checkLibraries, interval);
                    } else {
                        reject(new Error('highlight.js failed to load'));
                    }
                    return;
                }

                // All libraries are loaded
                resolve();
            }

            checkLibraries();
        });
    }

    function initializeLibraries() {
        try {
            md = window.markdownit({
                html: true,
                linkify: true,
                typographer: true,
                highlight: function (str, lang) {
                    if (lang && window.hljs.getLanguage(lang)) {
                        try {
                            return window.hljs.highlight(str, { language: lang }).value;
                        } catch (__) {}
                    }
                    return window.hljs.highlightAuto(str).value;
                }
            });

            window.hljs.configure({
                ignoreUnescapedHTML: true,
                languages: ['javascript', 'python', 'php', 'java', 'cpp', 'css', 'xml', 'bash', 'json']
            });

            return true;
        } catch (error) {
            console.error('[WP Bedrock] Failed to initialize libraries:', error);
            return false;
        }
    }

    // Initialize
    waitForLibraries()
        .then(() => {
            if (initializeLibraries()) {
                initializeToolsModal(); // Initialize tools modal first
                setupEventListeners();
                updateMessageCount();
                
                // Add initial message if chat is empty
                if (messageHistory.length === 0) {
                    addMessage(wpbedrock_chat.initial_message || 'Hello! How can I assist you today?', false);
                }
            } else {
                throw new Error('Failed to initialize libraries');
            }
        })
        .catch(error => {
            console.error('[WP Bedrock] Initialization failed:', error);
            $('.chat-container').html('<div class="error-message">Error: Chat initialization failed. Please refresh the page.</div>');
        });
}

// Start initialization
initChatbot();
