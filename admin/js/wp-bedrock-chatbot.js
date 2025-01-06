jQuery(document).ready(function($) {
    if (!wpbedrock_chat) {
        console.error('WP Bedrock Chat configuration not found');
        return;
    }

    // Initialize markdown-it and highlight.js
    const md = window.markdownit({
        highlight: function (str, lang) {
            if (lang && hljs.getLanguage(lang)) {
                try {
                    return hljs.highlight(str, { language: lang }).value;
                } catch (__) {}
            }
            return ''; // use external default escaping
        }
    });

    // Chat state
    let isProcessing = false;
    let currentStreamingMessage = null;
    let typingQueue = [];
    let isTyping = false;
    let typingTimeout = null;
    let currentImage = null;
    let messageHistory = [];
    let currentEventSource = null;

    // Model type mappers
    const ClaudeMapper = {
        assistant: "assistant",
        user: "user",
        system: "user",
    };

    const MistralMapper = {
        system: "system",
        user: "user",
        assistant: "assistant",
    };

    // DOM Elements
    const elements = {
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
        exportChatButton: $('#export-chat'),
        promptTrigger: $('#wpaicg-prompt-trigger'),
        maskTrigger: $('#wpaicg-mask-trigger')
    };

    // Initialize UI
    function initializeUI() {
        // Initialize tooltips
        if ($.fn.tooltip) {
            $('.wp-bedrock-chat [title]').tooltip({
                position: { my: 'left+10 center', at: 'right center' }
            });
        }

        // Add copy-to-clipboard for code blocks
        $(document).on('click', '.copy-code', function() {
            const codeBlock = $(this).siblings('pre').find('code');
            navigator.clipboard.writeText(codeBlock.text()).then(() => {
                const originalText = $(this).text();
                $(this).text('Copied!');
                setTimeout(() => $(this).text(originalText), 2000);
            });
        });
    }

    // Message handling
    function createMessageElement(content, isUser = false, imageUrl = null) {
        const messageDiv = $('<div>')
            .addClass('chat-message')
            .addClass(isUser ? 'user' : 'ai');

        const contentDiv = $('<div>')
            .addClass('message-content');

        if (imageUrl) {
            contentDiv.append($('<img>').attr('src', imageUrl).addClass('message-image'));
        }

        // Render markdown for AI messages
        if (!isUser) {
            const rendered = md.render(content);
            // Add copy button to code blocks
            const withCopyButtons = rendered.replace(
                /<pre><code class="language-([^"]+)">/g,
                '<div class="code-block"><button class="copy-code button button-small">Copy</button><pre><code class="language-$1">'
            ).replace(/<\/code><\/pre>/g, '</code></pre></div>');
            contentDiv.html(withCopyButtons);
            // Initialize syntax highlighting
            contentDiv.find('pre code').each(function(i, block) {
                hljs.highlightElement(block);
            });
        } else {
            contentDiv.text(content);
        }

        messageDiv.append(contentDiv);

        // Add action buttons for AI messages
        if (!isUser) {
            const actionsDiv = $('<div>')
                .addClass('message-actions')
                .append(
                    $('<button>')
                        .addClass('button button-small')
                        .attr('title', 'Copy')
                        .html('<span class="dashicons dashicons-clipboard"></span>')
                        .on('click', () => copyToClipboard(content)),
                    $('<button>')
                        .addClass('button button-small')
                        .attr('title', 'Regenerate')
                        .html('<span class="dashicons dashicons-update"></span>')
                        .on('click', () => regenerateResponse(content))
                );
            messageDiv.append(actionsDiv);
        }

        return messageDiv;
    }

    function addMessage(content, isUser = false, imageUrl = null) {
        const messageDiv = createMessageElement(content, isUser, imageUrl);
        
        if (!isUser) {
            currentStreamingMessage = messageDiv.find('.message-content');
        }
        
        elements.messagesContainer.append(messageDiv);
        elements.messagesContainer.scrollTop(elements.messagesContainer[0].scrollHeight);

        // Add to message history with proper format for vision models
        messageHistory.push({
            role: isUser ? 'user' : 'assistant',
            content: imageUrl ? [
                { type: 'text', text: content },
                { type: 'image_url', image_url: { url: imageUrl } }
            ] : content
        });

        return messageDiv;
    }

    function updateStreamingMessage(text) {
        if (!currentStreamingMessage) return;
        
        // Render markdown for the accumulated text
        currentStreamingMessage.html(md.render(text));
        elements.messagesContainer.scrollTop(elements.messagesContainer[0].scrollHeight);
    }

    // UI Feedback
    function showTypingIndicator() {
        const indicator = $('<div>')
            .addClass('typing-indicator')
            .append($('<span>'))
            .append($('<span>'))
            .append($('<span>'));
        elements.messagesContainer.append(indicator);
        elements.messagesContainer.scrollTop(elements.messagesContainer[0].scrollHeight);
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
            addMessage(wpbedrock_chat.initial_message || 'Hello! How can I help you today?', false);
        }
    }

    function exportChat() {
        const chatContent = messageHistory.map(msg => {
            const role = msg.role === 'assistant' ? 'AI' : 'User';
            const content = Array.isArray(msg.content) 
                ? msg.content.map(c => c.text || '[Image]').join('\n')
                : msg.content;
            return `${role}: ${content}`;
        }).join('\n\n');

        const blob = new Blob([chatContent], { type: 'text/plain' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'chat-history.txt';
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    }

    // Utility Functions
    async function copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            // Show success feedback
            const button = $('.message-actions .dashicons-clipboard').parent();
            button.addClass('button-primary');
            setTimeout(() => button.removeClass('button-primary'), 1000);
        } catch (err) {
            console.error('Failed to copy text:', err);
        }
    }

    function regenerateResponse(previousPrompt) {
        // Remove the last assistant message
        messageHistory.pop();
        elements.messagesContainer.children().last().remove();
        
        // Resend the last user message
        sendMessage(previousPrompt);
    }

    // Image Handling
    function handleImageUpload(file) {
        if (!file || !file.type.startsWith('image/')) {
            alert('Please select a valid image file.');
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            currentImage = e.target.result;
            elements.previewImage.attr('src', currentImage);
            elements.imagePreview.show();
        };
        reader.onerror = function(e) {
            console.error('Error reading image:', e);
            alert('Error reading image file');
        };
        reader.readAsDataURL(file);
    }

    function removeImage() {
        currentImage = null;
        elements.previewImage.attr('src', '');
        elements.imagePreview.hide();
        elements.imageUpload.val('');
    }

    // Message Formatting
    function formatRequestBody(messages, modelConfig) {
        const model = modelConfig.model;
        const visionModel = model.includes('claude-3') || model.includes('gpt-4-vision');

        // Get tools if available
        const tools = (wpbedrock_chat.tools || []).map(tool => ({
            function: {
                name: tool.name,
                description: tool.description,
                parameters: tool.parameters
            }
        }));

        // Handle Nova models
        if (model.includes('amazon.nova')) {
            const systemMessage = messages.find(m => m.role === 'system');
            const conversationMessages = messages.filter(m => m.role !== 'system');

            const requestBody = {
                schemaVersion: "messages-v1",
                messages: conversationMessages.map(message => ({
                    role: message.role,
                    content: Array.isArray(message.content) 
                        ? message.content.map(item => {
                            if (item.text || typeof item === 'string') {
                                return { text: item.text || item };
                            }
                            if (item.image_url?.url) {
                                const url = item.image_url.url;
                                const colonIndex = url.indexOf(':');
                                const semicolonIndex = url.indexOf(';');
                                const comma = url.indexOf(',');
                                const mimeType = url.slice(colonIndex + 1, semicolonIndex);
                                const format = mimeType.split('/')[1];
                                const data = url.slice(comma + 1);
                                return {
                                    image: {
                                        format,
                                        source: { bytes: data }
                                    }
                                };
                            }
                            return item;
                        })
                        : [{ text: message.content }]
                })),
                inferenceConfig: {
                    temperature: modelConfig.temperature || 0.7,
                    top_p: modelConfig.top_p || 0.9,
                    top_k: modelConfig.top_k || 50,
                    max_new_tokens: modelConfig.max_tokens || 1000,
                    stopSequences: modelConfig.stop || []
                }
            };

            if (systemMessage) {
                requestBody.system = [{ text: systemMessage.content }];
            }

            if (tools.length > 0) {
                requestBody.toolConfig = {
                    tools: tools.map(tool => ({
                        toolSpec: {
                            name: tool.function.name,
                            description: tool.function.description,
                            inputSchema: {
                                json: {
                                    type: "object",
                                    properties: tool.function.parameters.properties,
                                    required: tool.function.parameters.required
                                }
                            }
                        }
                    })),
                    toolChoice: { auto: {} }
                };
            }

            return requestBody;
        }

        // Handle Titan models
        if (model.startsWith('amazon.titan')) {
            const inputText = messages
                .map(message => `${message.role}: ${Array.isArray(message.content) 
                    ? message.content.map(c => c.text || '[Image]').join('\n')
                    : message.content}`)
                .join('\n\n');

            return {
                inputText,
                textGenerationConfig: {
                    maxTokenCount: modelConfig.max_tokens,
                    temperature: modelConfig.temperature,
                    stopSequences: []
                }
            };
        }

        // Handle Mistral models
        if (model.includes('mistral.mistral')) {
            const formattedMessages = messages.map(message => ({
                role: MistralMapper[message.role] || 'user',
                content: Array.isArray(message.content)
                    ? message.content.map(c => c.text || '[Image]').join('\n')
                    : message.content
            }));

            const requestBody = {
                messages: formattedMessages,
                max_tokens: modelConfig.max_tokens || 4096,
                temperature: modelConfig.temperature || 0.7,
                top_p: modelConfig.top_p || 0.9
            };

            if (tools.length > 0) {
                requestBody.tool_choice = 'auto';
                requestBody.tools = tools;
            }

            return requestBody;
        }

        // Handle Claude models
        if (model.includes('anthropic.claude')) {
            const formattedMessages = messages.map(message => {
                const role = ClaudeMapper[message.role] || 'user';
                
                if (!visionModel || typeof message.content === 'string') {
                    return {
                        role,
                        content: message.content
                    };
                }

                return {
                    role,
                    content: message.content.map(item => {
                        if (item.text) {
                            return {
                                type: 'text',
                                text: item.text
                            };
                        }
                        if (item.image_url?.url) {
                            const url = item.image_url.url;
                            const colonIndex = url.indexOf(':');
                            const semicolonIndex = url.indexOf(';');
                            const comma = url.indexOf(',');
                            const mimeType = url.slice(colonIndex + 1, semicolonIndex);
                            const encodeType = url.slice(semicolonIndex + 1, comma);
                            const data = url.slice(comma + 1);

                            return {
                                type: 'image',
                                source: {
                                    type: encodeType,
                                    media_type: mimeType,
                                    data
                                }
                            };
                        }
                        return item;
                    })
                };
            });

            const requestBody = {
                anthropic_version: wpbedrock_chat.anthropic_version || '2023-01-01',
                max_tokens: modelConfig.max_tokens,
                messages: formattedMessages,
                temperature: modelConfig.temperature,
                top_p: modelConfig.top_p || 0.9,
                top_k: modelConfig.top_k || 5
            };

            if (tools.length > 0) {
                requestBody.tools = tools.map(tool => ({
                    name: tool.function.name,
                    description: tool.function.description,
                    input_schema: tool.function.parameters
                }));
            }

            return requestBody;
        }

        // Default format for other models
        return {
            messages: messages.map(message => ({
                role: message.role,
                content: message.content
            })),
            ...modelConfig
        };
    }

    // Streaming Implementation
    function setupEventSource(url) {
        if (currentEventSource) {
            currentEventSource.close();
        }

        currentEventSource = new EventSource(url);
        let accumulatedText = '';

        currentEventSource.onopen = function() {
            console.log('Stream connection opened');
            removeTypingIndicator();
        };

        currentEventSource.onmessage = function(e) {
            try {
                const data = JSON.parse(e.data);
                
                if (data.done) {
                    currentEventSource.close();
                    setProcessingState(false);
                } else if (data.error) {
                    handleStreamError('Server error: ' + data.error);
                } else if (data.chunk) {
                    const chunk = JSON.parse(data.chunk.bytes);
                    
                    if (chunk.type === 'content_block_delta' && chunk.delta.type === 'text_delta') {
                        accumulatedText += chunk.delta.text;
                        updateStreamingMessage(accumulatedText);
                    } else if (chunk.type === 'tool_calls') {
                        handleToolCalls(chunk.tool_calls);
                    }
                }
            } catch (error) {
                console.error('Error parsing stream message:', error);
                handleStreamError('Invalid response format');
            }
        };

        currentEventSource.onerror = function(e) {
            handleStreamError('Connection error');
        };

        return currentEventSource;
    }

    function handleStreamError(message) {
        console.error(message);
        if (currentStreamingMessage) {
            currentStreamingMessage.text('Error: ' + message);
        } else {
            addMessage('Error: ' + message);
        }
        removeTypingIndicator();
        setProcessingState(false);
        
        if (currentEventSource) {
            currentEventSource.close();
            currentEventSource = null;
        }
    }

    async function handleToolCalls(toolCalls) {
        const toolResults = [];
        
        for (const tool of toolCalls) {
            try {
                const functionName = tool.function.name;
                const args = JSON.parse(tool.function.arguments);
                
                if (wpbedrock_chat.tools && wpbedrock_chat.tools[functionName]) {
                    const result = await wpbedrock_chat.tools[functionName](args);
                    toolResults.push({
                        tool_call_id: tool.id,
                        content: typeof result === 'string' ? result : JSON.stringify(result)
                    });
                }
            } catch (error) {
                console.error('Tool call error:', error);
                toolResults.push({
                    tool_call_id: tool.id,
                    content: `Error: ${error.message}`
                });
            }
        }

        // Add tool calls and results to message history
        messageHistory.push(
            {
                role: 'assistant',
                content: '',
                tool_calls: toolCalls
            },
            ...toolResults.map(result => ({
                role: 'tool',
                content: result.content,
                tool_call_id: result.tool_call_id
            }))
        );
    }

    // Message Sending
    async function sendMessage(overrideMessage = null) {
        const message = overrideMessage || elements.messageInput.val().trim();
        if ((!message && !currentImage) || isProcessing) return;

        setProcessingState(true);

        // Add user message to chat
        addMessage(message, true, currentImage);
        elements.messageInput.val('');
        removeImage();

        // Prepare request parameters
        const modelConfig = {
            model: wpbedrock_chat.default_model,
            temperature: wpbedrock_chat.default_temperature,
            max_tokens: wpbedrock_chat.default_max_tokens,
            top_p: wpbedrock_chat.default_top_p,
            top_k: wpbedrock_chat.default_top_k,
            stop: wpbedrock_chat.default_stop_sequences
        };

        const requestBody = formatRequestBody(
            [
                // Add system prompt if configured
                ...(wpbedrock_chat.default_system_prompt ? [{
                    role: 'system',
                    content: wpbedrock_chat.default_system_prompt
                }] : []),
                ...messageHistory.slice(-8) // Keep last 4 exchanges
            ],
            modelConfig
        );

        const params = new URLSearchParams({
            action: 'wpbedrock_chat_message',
            nonce: wpbedrock_chat.nonce,
            stream: '1',
            body: JSON.stringify(requestBody)
        });

        // Show typing indicator
        showTypingIndicator();

        // Setup streaming
        try {
            setupEventSource(`${wpbedrock_chat.ajaxurl}?${params.toString()}`);
        } catch (error) {
            console.error('Failed to setup EventSource:', error);
            handleStreamError('Could not connect to the server');
        }
    }

    // Prompt Library
    const promptLibrary = {
        prompts: [
            {
                title: 'Code Review',
                content: 'Please review this code and suggest improvements:\n\n'
            },
            {
                title: 'Bug Fix',
                content: 'I have a bug in my code. Here\'s the problem:\n\n'
            },
            {
                title: 'Feature Planning',
                content: 'Help me plan the implementation of this feature:\n\n'
            },
            {
                title: 'Documentation',
                content: 'Help me write documentation for:\n\n'
            }
        ],
        show() {
            const $dialog = $('<div>')
                .attr('title', 'Prompt Library')
                .addClass('prompt-library-dialog');

            const $list = $('<div>').addClass('prompt-list');
            this.prompts.forEach(prompt => {
                $('<div>')
                    .addClass('prompt-item')
                    .append(
                        $('<h4>').text(prompt.title),
                        $('<p>').text(prompt.content.substring(0, 100) + '...'),
                        $('<button>')
                            .addClass('button button-small')
                            .text('Use')
                            .on('click', () => {
                                elements.messageInput.val(prompt.content);
                                $dialog.dialog('close');
                            })
                    )
                    .appendTo($list);
            });

            $dialog.append($list);

            $dialog.dialog({
                width: 500,
                modal: true,
                classes: {
                    'ui-dialog': 'wp-dialog'
                },
                close: function() {
                    $(this).dialog('destroy').remove();
                }
            });
        }
    };

    // Conversation Masks
    const conversationMasks = {
        masks: [
            {
                title: 'Code Assistant',
                systemPrompt: 'You are an expert programmer. Help users with coding questions, debugging, and best practices. Provide clear explanations and code examples when appropriate.'
            },
            {
                title: 'Technical Writer',
                systemPrompt: 'You are a technical documentation expert. Help users write clear, concise, and accurate documentation for their code and projects.'
            },
            {
                title: 'DevOps Engineer',
                systemPrompt: 'You are a DevOps expert. Help users with deployment, infrastructure, and automation questions. Provide practical solutions and best practices.'
            }
        ],
        show() {
            const $dialog = $('<div>')
                .attr('title', 'Conversation Masks')
                .addClass('masks-dialog');

            const $list = $('<div>').addClass('masks-list');
            this.masks.forEach(mask => {
                $('<div>')
                    .addClass('mask-item')
                    .append(
                        $('<h4>').text(mask.title),
                        $('<p>').text(mask.systemPrompt.substring(0, 100) + '...'),
                        $('<button>')
                            .addClass('button button-small')
                            .text('Apply')
                            .on('click', () => {
                                clearChat();
                                $dialog.dialog('close');
                            })
                    )
                    .appendTo($list);
            });

            $dialog.append($list);

            $dialog.dialog({
                width: 500,
                modal: true,
                classes: {
                    'ui-dialog': 'wp-dialog'
                },
                close: function() {
                    $(this).dialog('destroy').remove();
                }
            });
        }
    };

    // Event Listeners
    function setupEventListeners() {
        elements.sendButton.on('click', () => sendMessage());
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
                handleImageUpload(this.files[0]);
            }
        });
        
        elements.removeImageButton.on('click', removeImage);
        elements.clearChatButton.on('click', clearChat);
        elements.exportChatButton.on('click', exportChat);
        elements.promptTrigger.on('click', () => promptLibrary.show());
        elements.maskTrigger.on('click', () => conversationMasks.show());

        elements.messageInput.on('keypress', function(e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        }).on('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
    }

    // Initialize
    initializeUI();
    setupEventListeners();
});
