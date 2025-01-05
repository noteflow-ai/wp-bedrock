jQuery(document).ready(function($) {
    if (!wpbedrock_chat) {
        console.error('WP Bedrock Chat configuration not found');
        return;
    }

    const messagesContainer = $('#wpaicg-chat-messages');
    const messageInput = $('#wpaicg-chat-message');
    const sendButton = $('#wpaicg-send-message');
    const chatHistory = $('#wpaicg-chat-history');
    let isProcessing = false;
    let currentStreamingMessage = null;
    let typingQueue = [];
    let isTyping = false;
    let typingTimeout = null;

    function addMessage(message, isUser = false) {
        const messageDiv = $('<div>')
            .addClass('wpaicg-chat-message')
            .addClass(isUser ? 'wpaicg-user-message' : 'wpaicg-ai-message')
            .text(message);
        
        if (!isUser) {
            currentStreamingMessage = messageDiv;
        }
        
        messagesContainer.append(messageDiv);
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight);

        // Add to history table
        const now = new Date();
        const timeStr = now.toLocaleTimeString();
        const historyRow = $('<tr>').append(
            $('<td>').text(timeStr),
            $('<td>').text(isUser ? message : ''),
            $('<td>').text(isUser ? '' : message)
        );
        chatHistory.prepend(historyRow);
    }

    function updateStreamingMessage(text) {
        if (!currentStreamingMessage) return;
        console.log('Received chunk to type:', text);

        // Split text into characters and add to queue
        const chars = Array.from(text);
        typingQueue.push(...chars);

        // Start typing if not already typing
        if (!isTyping) {
            console.log('Starting to type immediately');
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
        currentStreamingMessage.text(currentStreamingMessage.text() + char);
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight);

        // Schedule next character
        typingTimeout = setTimeout(typeNextChar, 30);
    }

    function addLoadingIndicator() {
        const loading = $('<div>')
            .addClass('wpaicg-chat-loading')
            .append($('<span>'))
            .append($('<span>'))
            .append($('<span>'));
        messagesContainer.append(loading);
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
    }

    function removeLoadingIndicator() {
        $('.wpaicg-chat-loading').remove();
    }

    function isStreamingEnabled() {
        const streamEnabled = wpbedrock_chat.enable_stream === '1' || wpbedrock_chat.enable_stream === true;
        return streamEnabled;
    }

    function setupEventSource(url) {
        let retryCount = 0;
        const MAX_RETRIES = 3;
        const RETRY_DELAY = 1000; // 1 second
        
        function createEventSource() {
            const eventSource = new EventSource(url);
            console.log('Creating new EventSource connection...');

            eventSource.onopen = function() {
                console.log('Stream connection opened successfully');
                retryCount = 0; // Reset retry count on successful connection
                removeLoadingIndicator();
            };

            eventSource.onmessage = function(e) {
                try {
                    console.log('Stream message received:', e.data);
                    const data = JSON.parse(e.data);
                    
                    if (data.done) {
                        console.log('Stream completed successfully');
                        eventSource.close();
                        isProcessing = false;
                        sendButton.prop('disabled', false);
                    } else if (data.error) {
                        console.error('Stream error received:', data.error);
                        handleStreamError(eventSource, 'Server error: ' + data.error);
                    } else if (data.text) {
                        updateStreamingMessage(data.text);
                    }
                } catch (error) {
                    console.error('Error parsing stream message:', error);
                    handleStreamError(eventSource, 'Invalid response format');
                }
            };

            eventSource.onerror = function(e) {
                console.error('Stream connection error:', e);
                
                if (retryCount < MAX_RETRIES) {
                    retryCount++;
                    console.log(`Connection lost. Retrying (${retryCount}/${MAX_RETRIES}) in ${RETRY_DELAY}ms...`);
                    
                    eventSource.close();
                    setTimeout(() => {
                        console.log('Attempting to reconnect...');
                        createEventSource();
                    }, RETRY_DELAY);
                } else {
                    handleStreamError(eventSource, 'Connection failed after multiple retries');
                }
            };

            return eventSource;
        }

        function handleStreamError(eventSource, errorMessage) {
            console.error(errorMessage);
            if (currentStreamingMessage) {
                currentStreamingMessage.text('Error: ' + errorMessage);
            } else {
                addMessage('Error: ' + errorMessage);
            }
            removeLoadingIndicator();
            eventSource.close();
            isProcessing = false;
            sendButton.prop('disabled', false);
        }

        return createEventSource();
    }

    function sendMessage() {
        const message = messageInput.val().trim();
        if (!message || isProcessing) return;

        isProcessing = true;
        sendButton.prop('disabled', true);
        
        // Reset typing state
        typingQueue = [];
        isTyping = false;
        if (typingTimeout) {
            clearTimeout(typingTimeout);
            typingTimeout = null;
        }
        
        // Add user message to chat
        addMessage(message, true);
        messageInput.val('');

        if (isStreamingEnabled()) {
            console.log('Streaming mode:', "Streaming Enabled!");
            // Show loading indicator
            addLoadingIndicator();
            
            // Create empty AI message div for streaming
            const messageDiv = $('<div>')
                .addClass('wpaicg-chat-message')
                .addClass('wpaicg-ai-message');
            currentStreamingMessage = messageDiv;
            messagesContainer.append(messageDiv);

            // Debug logging
            console.log('Setting up streaming connection...');
            
            // Construct URL with all parameters
            const params = new URLSearchParams({
                action: 'wpbedrock_chat_message',
                message: message,
                nonce: wpbedrock_chat.nonce,
                stream: '1'
            });
            const url = `${wpbedrock_chat.ajaxurl}?${params.toString()}`;
            
            console.log('Stream URL:', url);
            
            try {
                // Set up EventSource
                setupEventSource(url);
            } catch (error) {
                console.error('Failed to setup EventSource:', error);
                addMessage('Error: Could not connect to the server. Please try again.');
                removeLoadingIndicator();
                isProcessing = false;
                sendButton.prop('disabled', false);
            }
        } else {
            // Show loading indicator for non-streaming mode
            addLoadingIndicator();

            // Regular AJAX request
            $.ajax({
                url: wpbedrock_chat.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wpbedrock_chat_message',
                    message: message,
                    nonce: wpbedrock_chat.nonce
                },
                success: function(response) {
                    removeLoadingIndicator();
                    if (response.success) {
                        addMessage(response.data);
                    } else {
                        addMessage('Error: ' + response.data);
                    }
                },
                error: function() {
                    removeLoadingIndicator();
                    addMessage('Error: Could not connect to the server');
                },
                complete: function() {
                    isProcessing = false;
                    sendButton.prop('disabled', false);
                }
            });
        }
    }

    // Event handlers
    sendButton.on('click', sendMessage);

    messageInput.on('keypress', function(e) {
        if (e.which === 13 && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Auto-resize textarea
    messageInput.on('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
});
