// Wait for DOM and required libraries to be loaded
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

        if (!isUser && typeof content === 'string') {
            // Process markdown and add the HTML content
            contentDiv.html(processMarkdown(content));
            // Initialize syntax highlighting for code blocks
            contentDiv.find('pre code').each(function(i, block) {
                window.hljs.highlightElement(block);
            });
        } else {
            contentDiv.text(content);
        }

        containerDiv.append(headerDiv, contentDiv);
        messageDiv.append(containerDiv);

        return messageDiv;
    }

    function addMessage(content, isUser = false, imageUrl = null) {
        const messageDiv = createMessageElement(content, isUser, imageUrl);
        
        if (!isUser) {
            currentStreamingMessage = messageDiv.find('.message-content');
        }
        
        elements.messagesContainer.append(messageDiv);
        scrollToBottom();

        // Only include image in history if URL is valid
        const messageContent = imageUrl && imageUrl !== 'null' ? [
            { type: 'text', text: content },
            { type: 'image_url', image_url: { url: imageUrl } }
        ] : content;

        messageHistory.push({
            role: isUser ? 'user' : 'assistant',
            content: messageContent
        });

        updateMessageCount();
        return messageDiv;
    }

    function updateStreamingMessage(text) {
        if (!currentStreamingMessage) return;
        currentStreamingMessage.html(processMarkdown(text));
        scrollToBottom();
    }

    function scrollToBottom() {
        elements.messagesContainer.scrollTop(elements.messagesContainer[0].scrollHeight);
    }

    // Stream Processing
    async function processMessage(data) {
        if (!data) return;

        try {
            // Handle tool calls
            if (data.contentBlockStart?.start?.toolUse) {
                const toolUse = data.contentBlockStart.start.toolUse;
                toolIndex += 1;
                runTools.push({
                    id: toolUse.toolUseId,
                    type: 'function',
                    function: {
                        name: toolUse.name || '',
                        arguments: '{}'
                    }
                });
                return;
            }

            // Handle tool input
            if (data.contentBlockDelta?.delta?.toolUse?.input) {
                if (runTools[toolIndex]) {
                    runTools[toolIndex].function.arguments = data.contentBlockDelta.delta.toolUse.input;
                    
                    // Execute tool when arguments are complete
                    const toolResult = await executeTool(runTools[toolIndex]);
                    addMessage(`Tool Result (${toolResult.name}): ${toolResult.content}`, false);
                }
                return;
            }

            // Handle text content
            if (data.output?.message?.content?.[0]?.text) {
                remainText += data.output.message.content[0].text;
                updateStreamingMessage(remainText);
                return;
            }

            // Handle text delta
            if (data.contentBlockDelta?.delta?.text) {
                remainText += data.contentBlockDelta.delta.text;
                updateStreamingMessage(remainText);
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
                updateStreamingMessage(remainText);
            }
        } catch (e) {
            console.warn('[WP Bedrock] Failed to process message:', e);
        }
    }

    function processChunk(chunk) {
        try {
            const decoder = new TextDecoder('utf-8');
            const text = decoder.decode(chunk);
            const data = JSON.parse(text);

            if (data.bytes) {
                const decoded = atob(data.bytes);
                try {
                    const decodedJson = JSON.parse(decoded);
                    processMessage(decodedJson);
                } catch (e) {
                    processMessage({ output: decoded });
                }
                return;
            }

            processMessage(data);
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
    async function sendMessage() {
        const message = elements.messageInput.val().trim();
        if (!message || isProcessing) return;

        // Get image URL only if preview is visible and image source is valid
        const imageUrl = elements.imagePreview.is(':visible') ? 
            (elements.previewImage.attr('src') || null) : null;
        addMessage(message, true, imageUrl);
        elements.messageInput.val('');
        elements.imagePreview.hide();
        elements.imageUpload.val('');

        setProcessingState(true);
        showTypingIndicator();

        try {
            const response = await fetch(wpbedrock_chat.ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'wpbedrock_chat',
                    nonce: wpbedrock_chat.nonce,
                    message: message,
                    image: imageUrl,
                    history: JSON.stringify(messageHistory.slice(-wpbedrock_chat.context_length)), // Only send last N messages
                    stream: wpbedrock_chat.enable_stream ? '1' : '0',
                    model: wpbedrock_chat.default_model,
                    temperature: wpbedrock_chat.default_temperature,
                    system_prompt: wpbedrock_chat.default_system_prompt,
                    tools: JSON.stringify(selectedTools)
                })
            });

            if (!response.ok) throw new Error('Network response was not ok');

            const reader = response.body.getReader();
            remainText = '';
            runTools = [];
            toolIndex = -1;

            removeTypingIndicator();
            addMessage('', false); // Create empty message for streaming

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                await processChunk(value);
            }
        } catch (error) {
            console.error('[WP Bedrock] Chat error:', error);
            removeTypingIndicator();
            let errorMessage = 'An error occurred. Please try again.';
            if (error.message.includes('AWS credentials not configured')) {
                errorMessage = 'AWS credentials are not configured. Please go to Bedrock AI Agent > Settings to configure your AWS credentials.';
            }
            addMessage(errorMessage, false);
        } finally {
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
