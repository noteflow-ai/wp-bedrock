// Message formatters
const formatContentItem = (item) => {
    try {
        // Handle string input
        if (typeof item === "string") {
            return { type: "text", text: item };
        }

        // Handle null/undefined
        if (!item) {
            console.warn('[BedrockAPI] Received null/undefined content item');
            return null;
        }

        // Handle text content
        if (item.text || item.type === "text") {
            return { type: "text", text: item.text || item.content || "" };
        }

        // Handle image content
        if (item.image_url?.url) {
            const url = item.image_url.url;
            const colonIndex = url.indexOf(":");
            const semicolonIndex = url.indexOf(";");
            const comma = url.indexOf(",");

            if (colonIndex >= 0 && semicolonIndex >= 0 && comma >= 0) {
                const mimeType = url.slice(colonIndex + 1, semicolonIndex);
                const format = mimeType.split("/")[1];
                const data = url.slice(comma + 1);

                if (!format || !data) {
                    console.warn('[BedrockAPI] Invalid image data format');
                    return null;
                }

                return {
                    type: "image",
                    source: {
                        type: "base64",
                        media_type: mimeType,
                        data: data
                    }
                };
            }
            console.warn('[BedrockAPI] Invalid image URL format');
            return null;
        }

        console.warn('[BedrockAPI] Unknown content item type');
        return null;
    } catch (error) {
        console.error('[BedrockAPI] Error formatting content item:', error);
        return null;
    }
};

const formatRequestBody = (messages, modelConfig, tools = []) => {
    if (!Array.isArray(messages)) {
        throw new Error('Invalid messages format');
    }

    if (!modelConfig?.model) {
        throw new Error('Model ID is required');
    }

    const model = modelConfig.model;

    // Format messages based on model type
    if (model.includes("anthropic.claude")) {
        return formatClaudeRequest(messages, modelConfig, tools);
    } else if (model.includes("us.amazon.nova")) {
        return formatNovaRequest(messages, modelConfig, tools);
    } else if (model.startsWith("amazon.titan")) {
        return formatTitanRequest(messages, modelConfig);
    } else if (model.includes("meta.llama")) {
        return formatLlamaRequest(messages, modelConfig);
    } else if (model.includes("mistral.mistral")) {
        return formatMistralRequest(messages, modelConfig, tools);
    }

    throw new Error(`Unsupported model: ${model}`);
};

const ClaudeMapper = {
    system: "user",
    user: "user",
    assistant: "assistant"
};

const formatClaudeRequest = (messages, modelConfig, tools) => {
    // Convert messages to Claude format and handle role mapping
    let formattedMessages = messages.map(message => ({
        role: ClaudeMapper[message.role] || "user",
        content: Array.isArray(message.content)
            ? message.content.map(item => {
                const formatted = formatContentItem(item);
                return formatted?.text || "";
              }).join("\n")
            : message.content
    }));

    // Insert semicolon placeholder between consecutive user messages
    for (let i = 0; i < formattedMessages.length - 1; i++) {
        const message = formattedMessages[i];
        const nextMessage = formattedMessages[i + 1];
        
        if (message.role === "user" && nextMessage.role === "user") {
            formattedMessages.splice(i + 1, 0, {
                role: "assistant",
                content: ";"
            });
            i++; // Skip the inserted message
        }
    }

    // Ensure first message is from user
    if (formattedMessages[0]?.role === "assistant") {
        formattedMessages.unshift({
            role: "user",
            content: ";"
        });
    }

    // Add system prompt as a user message if present
    if (modelConfig.system_prompt) {
        formattedMessages.unshift({
            role: "user",
            content: modelConfig.system_prompt
        });
    }

    const requestBody = {
        messages: formattedMessages,
        max_tokens: modelConfig.max_tokens || 2000,
        temperature: modelConfig.temperature || 0.7,
        top_p: modelConfig.top_p || 0.9
    };

    if (tools && tools.length > 0) {
        requestBody.tools = tools.map(tool => {
            const params = tool?.function?.parameters || {};
            return {
                name: tool?.function?.name || "",
                description: tool?.function?.description || "",
                parameters: {
                    type: "object",
                    properties: params.properties || {},
                    required: params.required || []
                }
            };
        });
    }

    return requestBody;
};

const formatNovaRequest = (messages, modelConfig, tools) => {
    try {
        const systemMessage = messages.find(m => m.role === "system");
        const conversationMessages = messages.filter(m => m.role !== "system");

        // Ensure all text content is properly stringified
        const formatTextContent = (content) => {
            if (Array.isArray(content)) {
                return content.map(item => {
                    const formatted = formatContentItem(item);
                    return formatted?.text || "";
                }).join("\n");
            }
            return String(content || "");
        };

        const requestBody = {
            messages: conversationMessages.map(message => ({
                role: String(message.role),
                content: Array.isArray(message.content)
                    ? message.content.map(item => {
                        const formatted = formatContentItem(item);
                        if (!formatted) return null;

                        if (formatted.type === "text") {
                            return { text: String(formatted.text) };
                        } else if (formatted.type === "image") {
                            return { 
                                image: formatted.source
                            };
                        }
                        return null;
                    }).filter(Boolean)
                    : [{ text: String(message.content) }]
            })),
            temperature: Number(modelConfig.temperature || 0.7),
            top_k: Number(modelConfig.top_k || 50),
            max_tokens: Number(modelConfig.max_tokens || 1000),
            stop_sequences: Array.isArray(modelConfig.stop) ? modelConfig.stop : []
        };

        if (systemMessage) {
            requestBody.system = [{
                text: formatTextContent(systemMessage.content)
            }];
        }

        // Simplify tool configuration
        if (Array.isArray(tools) && tools.length > 0) {
            requestBody.toolConfig = {
                tools: tools.map(tool => ({
                    toolSpec: {
                        name: String(tool?.function?.name || ""),
                        description: String(tool?.function?.description || ""),
                        inputSchema: {
                            json: JSON.stringify(tool?.function?.parameters || {})
                        }
                    }
                })),
                toolChoice: { auto: {} }
            };
        }

        // Validate the request body can be properly serialized
        JSON.parse(JSON.stringify(requestBody));
        
        return requestBody;
    } catch (error) {
        console.error('[BedrockAPI] Error formatting Nova request:', error);
        throw new Error('Failed to format Nova request: ' + error.message);
    }
};

const formatTitanRequest = (messages, modelConfig) => {
    const inputText = messages
        .map(message => {
            const content = Array.isArray(message.content)
                ? message.content.map(item => {
                    const formatted = formatContentItem(item);
                    return formatted?.text || "";
                }).join("\n")
                : message.content;
            return `${message.role}: ${content}`;
        })
        .join("\n\n");

    return {
        inputText,
        textGenerationConfig: {
            maxTokenCount: modelConfig.max_tokens || 4096,
            temperature: modelConfig.temperature || 0.7,
            stopSequences: modelConfig.stop || []
        }
    };
};

const formatLlamaRequest = (messages, modelConfig) => {
    let prompt = "<|begin_of_text|>";
    
    const systemMessage = messages.find(m => m.role === "system");
    if (systemMessage) {
        const content = Array.isArray(systemMessage.content)
            ? systemMessage.content.map(item => {
                const formatted = formatContentItem(item);
                return formatted?.text || "";
            }).join("\n")
            : systemMessage.content;
        prompt += `<|start_header_id|>system<|end_header_id|>\n${content}<|eot_id|>`;
    }

    const conversationMessages = messages.filter(m => m.role !== "system");
    for (const message of conversationMessages) {
        const role = message.role === "assistant" ? "assistant" : "user";
        const content = Array.isArray(message.content)
            ? message.content.map(item => {
                const formatted = formatContentItem(item);
                return formatted?.text || "";
            }).join("\n")
            : message.content;
        prompt += `<|start_header_id|>${role}<|end_header_id|>\n${content}<|eot_id|>`;
    }

    prompt += "<|start_header_id|>assistant<|end_header_id|>";

    return {
        prompt,
        max_gen_len: modelConfig.max_tokens || 512,
        temperature: modelConfig.temperature || 0.7,
        top_p: modelConfig.top_p || 0.9
    };
};

const formatMistralRequest = (messages, modelConfig, tools) => {
    const formattedMessages = messages.map(message => ({
        role: message.role === "system" ? "system" :
              message.role === "assistant" ? "assistant" : "user",
        content: Array.isArray(message.content)
            ? message.content.map(item => {
                const formatted = formatContentItem(item);
                return formatted?.text || "";
            }).join("\n")
            : message.content
    }));

    const requestBody = {
        messages: formattedMessages,
        max_tokens: modelConfig.max_tokens || 4096,
        temperature: modelConfig.temperature || 0.7,
        top_p: modelConfig.top_p || 0.9
    };

    if (tools.length > 0) {
        requestBody.tool_choice = "auto";
        requestBody.tools = tools.map(tool => ({
            type: "function",
            function: {
                name: tool?.function?.name,
                description: tool?.function?.description,
                parameters: tool?.function?.parameters
            }
        }));
    }

    return requestBody;
};

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
            console.log(`[AI Chat for Amazon Bedrock] Waiting for libraries (attempt ${window.wpBedrockInitAttempts}/${MAX_ATTEMPTS}):`, missing.join(', '));
            setTimeout(initChatbot, 100);
            return;
        } else {
            console.error('[AI Chat for Amazon Bedrock] Failed to load required libraries after 10 seconds:', missing.join(', '));
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

    console.log('[AI Chat for Amazon Bedrock] All dependencies loaded, initializing chatbot...');
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
    let selectedTools = [];

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
            console.error('[AI Chat for Amazon Bedrock] Tool execution failed:', error);
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
            const avatarImg = $('<img>')
                .attr({
                    src: wpbedrock_chat.ai_avatar || `${wpbedrock_chat.plugin_url}images/ai-avatar.svg`,
                    alt: 'AI',
                    width: 35,
                    height: 35
                })
                .css('border-radius', '50%')
                .on('error', function() {
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

        updateMessageContent(contentDiv, content, !isUser);

        return messageDiv;
    }

    let md;

    function processMarkdown(content) {
        try {
            return md.render(content);
        } catch (e) {
            console.warn('[AI Chat for Amazon Bedrock] Failed to process markdown:', e);
            return content;
        }
    }

    function updateMessageContent(contentDiv, content, processAsMarkdown = false) {
        if (processAsMarkdown && typeof content === 'string') {
            if (content.includes('```') || content.includes('**') || content.includes('__')) {
                contentDiv.html(processMarkdown(content));
                contentDiv.find('pre code').each(function(i, block) {
                    window.hljs.highlightElement(block);
                });
            } else {
                contentDiv.text(content);
            }
        } else {
            contentDiv.text(content);
        }
    }

    function addMessage(content, isUser = false, imageUrl = null) {
        const messageDiv = createMessageElement(content, isUser, imageUrl);
        
        if (!isUser) {
            if (!currentStreamingMessage) {
                currentStreamingMessage = messageDiv.find('.message-content');
                if (content && typeof content === 'string') {
                    updateMessageContent(currentStreamingMessage, content, true);
                }
            }
        }
        
        elements.messagesContainer.append(messageDiv);
        scrollToBottom();

        // Format message content for storage
        let messageContent;
        if (imageUrl) {
            messageContent = [
                { type: "text", text: content },
                { image_url: { url: imageUrl } }
            ];
        } else {
            messageContent = [{ type: "text", text: content }];
        }

        const historyEntry = {
            role: isUser ? 'user' : 'assistant',
            content: messageContent
        };

        messageHistory = [...messageHistory, historyEntry];
        updateMessageCount();
        return messageDiv;
    }

    function scrollToBottom() {
        elements.messagesContainer.scrollTop(elements.messagesContainer[0].scrollHeight);
    }

    // Chat Management
    function clearChat() {
        if (confirm('Are you sure you want to clear the chat history?')) {
            elements.messagesContainer.empty();
            messageHistory = [];
            
            const model = wpbedrock_chat.default_model;
            const initialMessage = wpbedrock_chat.initial_message || 'Hello! How can I assist you today?';
            
            if (model.includes("anthropic.claude")) {
                // Claude requires user message first, but we don't need to show it
                messageHistory = [
                    {
                        role: 'user',
                        content: [{ type: "text", text: ";" }]
                    },
                    {
                        role: 'assistant',
                        content: [{ type: "text", text: initialMessage }]
                    }
                ];
            } else {
                // Default behavior for other models
                messageHistory = [{
                    role: 'assistant',
                    content: [{ type: "text", text: initialMessage }]
                }];
            }
            
            // Always show just the assistant's message
            const messageDiv = createMessageElement(initialMessage, false);
            elements.messagesContainer.append(messageDiv);
            updateMessageCount();
        }
    }

    function refreshChat() {
        location.reload();
    }

    async function copyChat() {
        const chatContent = messageHistory.map(msg => {
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
        }).join('\n\n');

        try {
            await navigator.clipboard.writeText(chatContent);
            const button = elements.exportChatButton;
            button.addClass('button-primary');
            setTimeout(() => button.removeClass('button-primary'), 1000);
        } catch (err) {
            console.error('[AI Chat for Amazon Bedrock] Failed to copy chat:', err);
            alert('Failed to copy chat to clipboard');
        }
    }

    function shareChat() {
        const chatContent = encodeURIComponent(
            messageHistory.map(msg => {
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

    function toggleLayout() {
        elements.chatContainer.toggleClass('wide-layout');
        elements.layoutTrigger.toggleClass('active');
    }

    // Initialize tools modal
    function initializeToolsModal() {
        const toolsModal = $('#tools-modal');
        
        toolsModal.dialog({
            autoOpen: false,
            modal: true,
            width: 600,
            dialogClass: 'tools-dialog'
        });
        
        $('.tool-item').on('click', function() {
            const $this = $(this);
            const toolDefinition = JSON.parse($this.attr('data-tool-definition'));
            
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
                const reader = new FileReader();
                reader.onload = function(e) {
                    elements.previewImage.attr('src', e.target.result);
                    elements.imagePreview.show();
                };
                reader.readAsDataURL(this.files[0]);
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

    function setProcessingState(processing) {
        isProcessing = processing;
        elements.sendButton.prop('disabled', processing);
        elements.stopButton.toggle(processing);
        elements.sendButton.toggle(!processing);
    }

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

    async function sendMessage() {
        const message = elements.messageInput.val().trim();
        if (!message || isProcessing) return;

        currentStreamingMessage = null;

        const imageUrl = elements.previewImage.attr('src');
        const maxContextLength = parseInt(wpbedrock_chat.context_messages) || 4;
        const contextCount = Math.min(messageHistory.length, maxContextLength);
        const previousMessages = messageHistory
            .slice(-contextCount)
            .map(msg => ({
                role: msg.role,
                content: msg.content
            }));

        // Add current message
        addMessage(message, true, imageUrl);
        elements.messageInput.val('');
        elements.imagePreview.hide();
        elements.imageUpload.val('');

        setProcessingState(true);
        if (wpbedrock_chat.enable_stream) {
            showTypingIndicator();
        }

        try {
            // Format request using formatRequestBody
            const modelConfig = {
                model: wpbedrock_chat.default_model,
                temperature: parseFloat(wpbedrock_chat.temperature) || 0.7,
                max_tokens: parseInt(wpbedrock_chat.max_tokens) || 2000,
                top_p: parseFloat(wpbedrock_chat.top_p) || 0.9,
                system_prompt: wpbedrock_chat.default_system_prompt
            };

            // Prepare message content
            let messageContent;
            if (imageUrl) {
                messageContent = [
                    { type: "text", text: message },
                    { 
                        image_url: { 
                            url: imageUrl 
                        }
                    }
                ];
            } else {
                messageContent = [{ type: "text", text: message }];
            }

            const requestBody = formatRequestBody(
                [...previousMessages, {
                    role: 'user',
                    content: messageContent
                }],
                modelConfig,
                selectedTools
            );

            console.log('[AI Chat for Amazon Bedrock] Formatted request body:', requestBody);

            // Prepare request data
            let response;
            try {
                const formData = new FormData();
                formData.append('action', 'wpbedrock_chat_message');
                formData.append('nonce', wpbedrock_chat.nonce);
                formData.append('requestBody', JSON.stringify(requestBody));
                formData.append('stream', wpbedrock_chat.enable_stream ? '1' : '0');
                formData.append('model_id', wpbedrock_chat.default_model);

                response = await fetch(wpbedrock_chat.ajaxurl, {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('[AI Chat for Amazon Bedrock] Server error response:', errorText);
                    throw new Error(`HTTP error! status: ${response.status}, message: ${errorText}`);
                }
            } catch (error) {
                console.error('[AI Chat for Amazon Bedrock] Request failed:', error);
                throw new Error('Failed to send request: ' + error.message);
            }

            // Handle streaming response
            if (wpbedrock_chat.enable_stream) {
                const reader = response.body?.getReader();
                if (!reader) throw new Error('No response body reader available');

                removeTypingIndicator();
                addMessage('', false);

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    const chunk = new TextDecoder().decode(value);
                    const lines = chunk.split('\n');

                    for (const line of lines) {
                        if (!line.startsWith('data: ')) continue;
                        
                        const data = JSON.parse(line.slice(6));
                        if (data.error) throw new Error(data.error);

                        let text = '';
                        if (data.text) {
                            text = data.text;
                        } else if (data.output_text) {
                            text = data.output_text;
                        } else if (data.output?.message?.content?.[0]?.text) {
                            text = data.output.message.content[0].text;
                        } else if (data.contentBlockDelta?.delta?.text) {
                            text = data.contentBlockDelta.delta.text;
                        } else if (data.delta?.text) {
                            text = data.delta.text;
                        } else if (data.output?.content?.[0]?.type === "text") {
                            text = data.output.content[0].text;
                        } else if (data.bytes) {
                            try {
                                const decoded = JSON.parse(atob(data.bytes));
                                if (decoded.output_text) {
                                    text = decoded.output_text;
                                }
                            } catch (e) {
                                console.warn('[BedrockAPI] Failed to decode bytes:', e);
                            }
                        }

                        if (text && currentStreamingMessage) {
                            const currentText = currentStreamingMessage.text();
                            updateMessageContent(currentStreamingMessage, currentText + text, true);
                            scrollToBottom();
                        }
                    }
                }
            } else {
                // Handle non-streaming response
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.data || 'Unknown error');
                }

                // Extract content from the response data
                const content = data.data.content || data.data;
                addMessage(content, false);
            }
        } catch (error) {
            console.error('[AI Chat for Amazon Bedrock] Chat error:', error);
            removeTypingIndicator();
            addMessage(`Error: ${error.message}`, false);
        } finally {
            setProcessingState(false);
        }
    }

    // Initialize libraries and start chat
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
            console.error('[AI Chat for Amazon Bedrock] Failed to initialize libraries:', error);
            return false;
        }
    }

    // Initialize
    if (initializeLibraries()) {
        initializeToolsModal();
        setupEventListeners();
        updateMessageCount();
        
        if (messageHistory.length === 0) {
            const model = wpbedrock_chat.default_model;
            const initialMessage = wpbedrock_chat.initial_message || 'Hello! How can I assist you today?';
            
            // Format initial messages based on model type
            if (model.includes("anthropic.claude")) {
                // Claude requires user message first, but we don't need to show it
                messageHistory = [
                    {
                        role: 'user',
                        content: [{ type: "text", text: ";" }]
                    },
                    {
                        role: 'assistant',
                        content: [{ type: "text", text: initialMessage }]
                    }
                ];
                // Only show the assistant's message
                const messageDiv = createMessageElement(initialMessage, false);
                elements.messagesContainer.append(messageDiv);
            } else if (model.includes("amazon.nova")) {
                // Nova can handle assistant first message
                messageHistory = [{
                    role: 'assistant',
                    content: [{ type: "text", text: initialMessage }]
                }];
                const messageDiv = createMessageElement(initialMessage, false);
                elements.messagesContainer.append(messageDiv);
            } else {
                // Default behavior for other models
                messageHistory = [{
                    role: 'assistant',
                    content: [{ type: "text", text: initialMessage }]
                }];
                const messageDiv = createMessageElement(initialMessage, false);
                elements.messagesContainer.append(messageDiv);
            }
            updateMessageCount();
        }
    } else {
        $('.chat-container').html('<div class="error-message">Error: Chat initialization failed. Please refresh the page.</div>');
    }
}

// Start initialization
initChatbot();
