/**
 * Response handler for managing API responses, streaming, and tool use
 */
class BedrockResponseHandler {
    constructor() {
        this.currentEventSource = null;
        this.streamBuffer = '';
        this.retryCount = 0;
        this.maxRetries = 3;
        this.timeout = 30000;
        this.timeoutId = null;
        this.pendingToolCalls = new Map();
        this.callbacks = {
            onContent: null,
            onError: null,
            onComplete: null,
            onRetry: null,
            onToolCall: null,
            onToolResult: null
        };
        this._lastRequest = null;
    }

    setCallbacks({ onContent, onError, onComplete, onRetry, onToolCall, onToolResult }) {
        this.callbacks = {
            ...this.callbacks,
            onContent: onContent || this.callbacks.onContent,
            onError: onError || this.callbacks.onError,
            onComplete: onComplete || this.callbacks.onComplete,
            onRetry: onRetry || this.callbacks.onRetry,
            onToolCall: onToolCall || this.callbacks.onToolCall,
            onToolResult: onToolResult || this.callbacks.onToolResult
        };
    }

    // Timer Management
    setTimeout() {
        this.clearTimeout();
        this.timeoutId = setTimeout(() => {
            const error = new Error('Request timeout');
            error.code = 'TIMEOUT';
            this.handleError(error);
        }, this.timeout);
    }

    clearTimeout() {
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
            this.timeoutId = null;
        }
    }

    reset() {
        this.stopStreaming();
        this.retryCount = 0;
        this.clearTimeout();
        this.streamBuffer = '';
        this._lastRequest = null;
    }

    // Error Handling
    formatErrorMessage(error) {
        return `<div class="error-message">
            <span class="dashicons dashicons-warning"></span>
            <span class="error-text">${this.getErrorText(error)}</span>
        </div>`;
    }

    getErrorText(error) {
        const errorMessages = {
            'TIMEOUT': 'Request timed out. Please try again.',
            'NETWORK_ERROR': 'Network connection error. Please check your connection.',
            'PARSE_ERROR': 'Error processing response. Please try again.',
            'STREAM_ERROR': 'Stream connection error. Please try again.',
            'HTTP_ERROR': `Server error (${error.message}). Please try again later.`,
            'API_ERROR': `API error: ${error.message}`
        };
        return errorMessages[error.code] || error.message || 'An unknown error occurred';
    }

    handleError(error) {
        this.clearTimeout();
        
        if (this.retryCount < this.maxRetries && 
            ['TIMEOUT', 'NETWORK_ERROR'].includes(error.code)) {
            this.retryCount++;
            if (this.callbacks.onRetry) {
                this.callbacks.onRetry(this.retryCount, error);
            }
            const delay = Math.min(1000 * Math.pow(2, this.retryCount - 1), 10000);
            setTimeout(() => this.retry(), delay);
            return;
        }

        if (this.callbacks.onError) {
            this.callbacks.onError(error);
        }
        this.reset();
    }

    // Stream Response Processing
    handleStreamResponse(response) {
        try {
            this.clearTimeout();
            this.setTimeout();

            // Handle Nova model responses
            if (response.output?.message?.content) {
                const result = this.processNovaResponse(response.output.message);
                if (result.content && this.callbacks.onContent) {
                    this.callbacks.onContent(result);
                }
                return;
            }

            // Handle tool calls and Claude's tool_use
            if (response.type === 'tool_call' || response.type === 'tool_use') {
                const toolCall = this.processToolCall(response);
                if (toolCall && this.callbacks.onToolCall) {
                    this.callbacks.onToolCall(toolCall);
                }
                return;
            }

            // Handle tool results
            if (response.type === 'tool_result') {
                const result = this.processToolResult(response);
                if (result && this.callbacks.onToolResult) {
                    this.callbacks.onToolResult(result);
                }
                return;
            }

            // Handle completion
            if (response.done) {
                this.handleStreamCompletion();
                return;
            }

            // Handle errors
            if (response.error) {
                const error = new Error(response.error);
                error.code = 'STREAM_ERROR';
                this.handleError(error);
                return;
            }

            // Handle regular content
            if (response.content) {
                this.appendToBuffer(response.content);
            }
        } catch (error) {
            error.code = 'PARSE_ERROR';
            this.handleError(error);
        }
    }

    // Non-Stream Response Processing
    async handleNonStreamResponse(response) {
        try {
            this.clearTimeout();
            const data = await this.parseResponseData(response);
            
            if (data.error) {
                const error = new Error(data.error);
                error.code = 'API_ERROR';
                throw error;
            }

            // Handle Nova model responses
            if (data.output?.message) {
                const message = data.output.message;
                const content = message.content?.[0]?.text || '';
                return {
                    type: 'text',
                    content: content,
                    role: message.role || 'assistant'
                };
            }

            // Handle Claude's content array format
            if (Array.isArray(data.content)) {
                for (const item of data.content) {
                    if (item.type === 'tool_use') {
                        const toolCall = this.processToolCall(item);
                        if (toolCall && this.callbacks.onToolCall) {
                            this.callbacks.onToolCall(toolCall);
                        }
                        return {
                            type: 'tool_call',
                            content: toolCall
                        };
                    } else if (item.type === 'text') {
                        this.appendToBuffer(item.text);
                    }
                }
            }

            // Handle tool calls and Claude's tool_use
            if (data.tool_calls || data.type === 'tool_use') {
                const toolCalls = data.tool_calls || [data];
                const processed = toolCalls.map(call => this.processToolCall(call));
                processed.forEach(toolCall => {
                    if (toolCall && this.callbacks.onToolCall) {
                        this.callbacks.onToolCall(toolCall);
                    }
                });
                return {
                    type: 'tool_call',
                    content: processed
                };
            }

            // Handle tool results
            if (data.tool_result || data.type === 'tool_result') {
                const result = this.processToolResult(data);
                if (result && this.callbacks.onToolResult) {
                    this.callbacks.onToolResult(result);
                }
                return {
                    type: 'tool_result',
                    content: result
                };
            }

            // Handle general content
            return this.processGeneralContent(data);

        } catch (error) {
            if (!error.code) {
                error.code = 'PARSE_ERROR';
            }
            this.handleError(error);
            throw error;
        }
    }

    // Alias for backward compatibility
    handleResponse(response) {
        return this.handleNonStreamResponse(response);
    }

    // Response Processing Helpers
    processNovaResponse(message) {
        if (!message || !message.content) {
            return {
                type: 'text',
                content: '',
                role: 'assistant'
            };
        }

        // Process array of content items
        if (Array.isArray(message.content)) {
            const text = message.content
                .filter(item => item && typeof item === 'object')
                .map(item => item.text || '')
                .join('');

            return {
                type: 'text',
                content: text,
                role: message.role || 'assistant'
            };
        }

        // Handle case where content might be directly in the message
        return {
            type: 'text',
            content: message.content.text || message.text || '',
            role: message.role || 'assistant'
        };
    }

    processToolCall(data) {
        // Handle Claude's tool_use format
        const toolCall = {
            id: data.id || `call_${Date.now()}`,
            name: data.name || data.tool_name,
            function: {
                name: data.name || data.tool_name,
                arguments: typeof data.input === 'string' ? 
                    data.input : 
                    JSON.stringify(data.input || data.arguments || data.tool_arguments || {})
            }
        };

        this.pendingToolCalls.set(toolCall.id, toolCall);

        if (this.callbacks.onContent) {
            this.callbacks.onContent({
                type: 'tool_call',
                content: toolCall
            });
        }

        return toolCall;
    }

    processToolCalls(toolCalls) {
        const processed = toolCalls.map(call => this.processToolCall(call));
        processed.forEach(toolCall => {
            if (toolCall && this.callbacks.onToolCall) {
                this.callbacks.onToolCall(toolCall);
            }
        });
        return {
            type: 'tool_call',
            content: processed
        };
    }

    processToolResult(data) {
        // Handle Claude's tool_use format
        const result = {
            tool_call_id: data.tool_use_id || data.id,
            name: data.name || data.tool_name,
            content: data.content || data.result || data.tool_result || data.data // Add data fallback for DuckDuckGo response
        };

        // Find and remove the matching tool call
        for (const [callId, toolCall] of this.pendingToolCalls.entries()) {
            if (callId === result.tool_call_id || toolCall.name === result.name) {
                this.pendingToolCalls.delete(callId);
                break;
            }
        }

        if (this.callbacks.onContent) {
            this.callbacks.onContent({
                type: 'tool_result',
                content: result
            });
        }

        return result;
    }

    processGeneralContent(data) {
        const content = data.content || data;
        const text = this.processTextContent(content);
        return {
            type: 'text',
            content: text
        };
    }

    processTextContent(content) {
        if (Array.isArray(content)) {
            return content
                .filter(item => item.text)
                .map(item => item.text)
                .join('');
        }
        return content?.text || content || '';
    }

    handleStreamCompletion() {
        this.clearTimeout();
        if (this.callbacks.onComplete) {
            this.callbacks.onComplete(this.streamBuffer);
        }
        this.reset();
    }

    // Response Data Parsing
    async parseResponseData(response) {
        if (response instanceof Response) {
            if (!response.ok) {
                const error = new Error(`HTTP error! status: ${response.status}`);
                error.code = 'HTTP_ERROR';
                throw error;
            }
            return await response.json();
        }
        return response;
    }

    appendToBuffer(text) {
        if (!text) return;
        
        this.streamBuffer += text;
        if (this.callbacks.onContent) {
            this.callbacks.onContent({
                type: 'text',
                content: text
            });
        }
    }

    // Streaming Control
    async startStreaming(url, requestBody) {
        try {
            this.reset();
            this.setTimeout();
            this._lastRequest = { url, requestBody };

            this.currentEventSource = new EventSource(url);

            this.currentEventSource.onmessage = (event) => {
                try {
                    const response = JSON.parse(event.data);
                    this.handleStreamResponse(response);
                } catch (error) {
                    error.code = 'PARSE_ERROR';
                    this.handleError(error);
                }
            };

            this.currentEventSource.onerror = (error) => {
                error.code = 'STREAM_ERROR';
                this.handleError(error);
            };

            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestBody)
            });

            if (!response.ok) {
                const error = new Error(`HTTP error! status: ${response.status}`);
                error.code = 'HTTP_ERROR';
                throw error;
            }

            return true;
        } catch (error) {
            if (!error.code) error.code = 'NETWORK_ERROR';
            this.handleError(error);
            return false;
        }
    }

    async retry() {
        if (this._lastRequest) {
            const { url, requestBody } = this._lastRequest;
            return this.startStreaming(url, requestBody);
        }
        return false;
    }

    stopStreaming() {
        if (this.currentEventSource) {
            this.currentEventSource.close();
            this.currentEventSource = null;
        }
        this.streamBuffer = '';
    }

    // Utility Methods
    getStreamBuffer() {
        return this.streamBuffer;
    }

    clearStreamBuffer() {
        this.streamBuffer = '';
    }

    isStreaming() {
        return this.currentEventSource !== null;
    }
}

// Export the response handler class
if (typeof window !== 'undefined' && !window.BedrockResponseHandler) {
    window.BedrockResponseHandler = BedrockResponseHandler;
}
