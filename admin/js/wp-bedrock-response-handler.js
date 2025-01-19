/**
 * Response handler for managing API responses and streaming
 */
class BedrockResponseHandler {
    constructor() {
        this.currentEventSource = null;
        this.streamBuffer = '';
        this.retryCount = 0;
        this.maxRetries = 3;
        this.timeout = 30000; // 30 seconds timeout
        this.timeoutId = null;
        this.callbacks = {
            onContent: null,
            onError: null,
            onComplete: null,
            onRetry: null
        };
    }

    // Set callback handlers
    setCallbacks({ onContent, onError, onComplete, onRetry }) {
        this.callbacks = {
            onContent: onContent || this.callbacks.onContent,
            onError: onError || this.callbacks.onError,
            onComplete: onComplete || this.callbacks.onComplete,
            onRetry: onRetry || this.callbacks.onRetry
        };
    }

    // Reset state
    reset() {
        this.stopStreaming();
        this.retryCount = 0;
        this.clearTimeout();
        this.streamBuffer = '';
        this._lastRequest = null;
    }

    // Set timeout
    setTimeout() {
        this.clearTimeout();
        this.timeoutId = setTimeout(() => {
            const error = new Error('Request timeout');
            error.code = 'TIMEOUT';
            this.handleError(error);
        }, this.timeout);
    }

    // Clear timeout
    clearTimeout() {
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
            this.timeoutId = null;
        }
    }

    // Format error message for display
    formatErrorMessage(error) {
        return `<div class="error-message">
            <span class="dashicons dashicons-warning"></span>
            <span class="error-text">${this.getErrorText(error)}</span>
        </div>`;
    }

    // Get human-readable error text
    getErrorText(error) {
        switch (error.code) {
            case 'TIMEOUT':
                return 'Request timed out. Please try again.';
            case 'NETWORK_ERROR':
                return 'Network connection error. Please check your connection.';
            case 'PARSE_ERROR':
                return 'Error processing response. Please try again.';
            case 'STREAM_ERROR':
                return 'Stream connection error. Please try again.';
            case 'HTTP_ERROR':
                return `Server error (${error.message}). Please try again later.`;
            case 'API_ERROR':
                return `API error: ${error.message}`;
            default:
                return error.message || 'An unknown error occurred';
        }
    }

    // Handle errors
    handleError(error) {
        this.clearTimeout();
        
        if (this.retryCount < this.maxRetries && 
            ['TIMEOUT', 'NETWORK_ERROR'].includes(error.code)) {
            this.retryCount++;
            if (this.callbacks.onRetry) {
                this.callbacks.onRetry(this.retryCount, error);
            }
            // Exponential backoff
            const delay = Math.min(1000 * Math.pow(2, this.retryCount - 1), 10000);
            setTimeout(() => this.retry(), delay);
            return;
        }

        if (this.callbacks.onError) {
            this.callbacks.onError(error);
        }
        this.reset();
    }

    // Handle streaming response
    handleStreamResponse(response) {
        try {
            this.clearTimeout();
            this.setTimeout();

            // Handle Nova model responses
            if (response.output?.message?.content) {
                const content = response.output.message.content;
                if (Array.isArray(content)) {
                    const text = content
                        .filter(item => item.text)
                        .map(item => item.text)
                        .join('');
                    this.streamBuffer += text;
                    if (this.callbacks.onContent) {
                        this.callbacks.onContent({
                            type: 'text',
                            content: text
                        });
                    }
                    return;
                }
            }

            // Handle base64 encoded responses
            if (response.bytes) {
                try {
                    const decoded = JSON.parse(atob(response.bytes));
                    
                    // Handle Nova content
                    if (decoded.results && Array.isArray(decoded.results)) {
                        const text = decoded.results[0]?.outputText || '';
                        this.streamBuffer += text;
                        if (this.callbacks.onContent) {
                            this.callbacks.onContent({
                                type: 'text',
                                content: text
                            });
                        }
                        return;
                    }

                    // Handle other content types
                    switch (decoded.type) {
                        case 'content_block_delta':
                            const text = decoded.delta.text || '';
                            this.streamBuffer += text;
                            if (this.callbacks.onContent) {
                                this.callbacks.onContent({
                                    type: 'text',
                                    content: text
                                });
                            }
                            break;

                        case 'tool_call':
                            if (this.callbacks.onContent) {
                                this.callbacks.onContent({
                                    type: 'tool_call',
                                    content: {
                                        name: decoded.name,
                                        arguments: decoded.arguments
                                    }
                                });
                            }
                            break;

                        case 'tool_result':
                            if (this.callbacks.onContent) {
                                this.callbacks.onContent({
                                    type: 'tool_result',
                                    content: {
                                        name: decoded.name,
                                        result: decoded.content
                                    }
                                });
                            }
                            break;

                        default:
                            console.warn('[BedrockResponseHandler] Unknown response type:', decoded.type);
                    }
                } catch (decodeError) {
                    console.error('[BedrockResponseHandler] Failed to decode base64:', decodeError);
                    throw decodeError;
                }
            } else if (response.error) {
                const error = new Error(response.error);
                error.code = 'STREAM_ERROR';
                this.handleError(error);
            } else if (response.done) {
                this.clearTimeout();
                if (this.callbacks.onComplete) {
                    this.callbacks.onComplete(this.streamBuffer);
                }
                this.reset();
            }
        } catch (error) {
            error.code = 'PARSE_ERROR';
            this.handleError(error);
        }
    }

    // Start streaming response
    async startStreaming(url, requestBody) {
        try {
            this.reset();
            this.setTimeout();

            // Store request for retries
            this._lastRequest = { url, requestBody };

            // Create new EventSource
            this.currentEventSource = new EventSource(url);

            // Set up event handlers
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

            // Send initial request
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(requestBody)
            });

            if (!response.ok) {
                const error = new Error(`HTTP error! status: ${response.status}`);
                error.code = 'HTTP_ERROR';
                throw error;
            }

            return true;
        } catch (error) {
            if (!error.code) {
                error.code = 'NETWORK_ERROR';
            }
            this.handleError(error);
            return false;
        }
    }

    // Retry current request
    async retry() {
        if (this._lastRequest) {
            const { url, requestBody } = this._lastRequest;
            return this.startStreaming(url, requestBody);
        }
        return false;
    }

    // Stop streaming response
    stopStreaming() {
        if (this.currentEventSource) {
            this.currentEventSource.close();
            this.currentEventSource = null;
        }
        this.streamBuffer = '';
    }

    // Handle non-streaming response
    async handleResponse(response) {
        try {
            this.clearTimeout();
            
            // Handle both fetch Response objects and jQuery ajax responses
            let data;
            if (response instanceof Response) {
                if (!response.ok) {
                    const error = new Error(`HTTP error! status: ${response.status}`);
                    error.code = 'HTTP_ERROR';
                    throw error;
                }
                data = await response.json();
            } else {
                // Assume jQuery ajax response
                data = response;
            }
            
            if (data.error) {
                const error = new Error(data.error);
                error.code = 'API_ERROR';
                throw error;
            }

            // Handle Nova model responses
            if (data.output?.message?.content) {
                const content = data.output.message.content;
                if (Array.isArray(content)) {
                    const textContent = content
                        .filter(item => item.text)
                        .map(item => item.text)
                        .join('');
                    return {
                        type: 'text',
                        content: textContent
                    };
                }
            }

            // Handle other model responses
            if (data.content) {
                if (Array.isArray(data.content)) {
                    // Handle array of content items
                    const textContent = data.content
                        .filter(item => item.type === 'text')
                        .map(item => item.text)
                        .join('');
                    return {
                        type: 'text',
                        content: textContent
                    };
                }
                return {
                    type: 'text',
                    content: data.content
                };
            } else if (data.tool_calls) {
                return {
                    type: 'tool_call',
                    content: data.tool_calls
                };
            } else if (data.tool_use) {
                return {
                    type: 'tool_result',
                    content: data.tool_use
                };
            }

            // If no recognized format, return raw data
            return {
                type: 'text',
                content: JSON.stringify(data)
            };
        } catch (error) {
            if (!error.code) {
                error.code = 'PARSE_ERROR';
            }
            this.handleError(error);
            throw error;
        }
    }

    // Get current stream buffer
    getStreamBuffer() {
        return this.streamBuffer;
    }

    // Clear stream buffer
    clearStreamBuffer() {
        this.streamBuffer = '';
    }

    // Check if currently streaming
    isStreaming() {
        return this.currentEventSource !== null;
    }
}

// Export the response handler class
if (typeof window !== 'undefined' && !window.BedrockResponseHandler) {
    window.BedrockResponseHandler = BedrockResponseHandler;
}
