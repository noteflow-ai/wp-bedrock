/**
 * Response handler for managing API responses and streaming
 */
class BedrockResponseHandler {
    constructor() {
        this.currentEventSource = null;
        this.streamBuffer = '';
        this.callbacks = {
            onContent: null,
            onError: null,
            onComplete: null
        };
    }

    // Set callback handlers
    setCallbacks({ onContent, onError, onComplete }) {
        this.callbacks = {
            onContent: onContent || this.callbacks.onContent,
            onError: onError || this.callbacks.onError,
            onComplete: onComplete || this.callbacks.onComplete
        };
    }

    // Handle streaming response
    handleStreamResponse(response) {
        try {
            // Handle different response formats
            if (response.bytes) {
                const decoded = JSON.parse(atob(response.bytes));
                if (decoded.type === 'content_block_delta') {
                    const text = decoded.delta.text || '';
                    this.streamBuffer += text;
                    if (this.callbacks.onContent) {
                        this.callbacks.onContent(text);
                    }
                }
            } else if (response.error) {
                console.error('[BedrockResponseHandler] Stream error:', response.error);
                if (this.callbacks.onError) {
                    this.callbacks.onError(response.error);
                }
            } else if (response.done) {
                if (this.callbacks.onComplete) {
                    this.callbacks.onComplete(this.streamBuffer);
                }
                this.streamBuffer = '';
            }
        } catch (error) {
            console.error('[BedrockResponseHandler] Error handling stream response:', error);
            if (this.callbacks.onError) {
                this.callbacks.onError(error);
            }
        }
    }

    // Start streaming response
    startStreaming(url, requestBody) {
        return new Promise((resolve, reject) => {
            try {
                // Close any existing stream
                this.stopStreaming();

                // Create new EventSource
                this.currentEventSource = new EventSource(url);

                // Set up event handlers
                this.currentEventSource.onmessage = (event) => {
                    try {
                        const response = JSON.parse(event.data);
                        this.handleStreamResponse(response);
                    } catch (error) {
                        console.error('[BedrockResponseHandler] Error parsing stream data:', error);
                        if (this.callbacks.onError) {
                            this.callbacks.onError(error);
                        }
                    }
                };

                this.currentEventSource.onerror = (error) => {
                    console.error('[BedrockResponseHandler] Stream error:', error);
                    this.stopStreaming();
                    if (this.callbacks.onError) {
                        this.callbacks.onError(error);
                    }
                    reject(error);
                };

                // Send initial request
                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(requestBody)
                }).catch(error => {
                    console.error('[BedrockResponseHandler] Error sending initial request:', error);
                    this.stopStreaming();
                    if (this.callbacks.onError) {
                        this.callbacks.onError(error);
                    }
                    reject(error);
                });

                resolve();
            } catch (error) {
                console.error('[BedrockResponseHandler] Error starting stream:', error);
                if (this.callbacks.onError) {
                    this.callbacks.onError(error);
                }
                reject(error);
            }
        });
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
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }

            return data;
        } catch (error) {
            console.error('[BedrockResponseHandler] Error handling response:', error);
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
