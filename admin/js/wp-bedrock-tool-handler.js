/**
 * Tool handler for managing tool calls and results
 */
class BedrockToolHandler {
    constructor() {
        this.tools = new Map();
        this.toolResults = new Map();
        this.pendingToolCalls = new Map();
    }

    // Register a new tool
    registerTool(name, handler) {
        if (typeof handler !== 'function') {
            throw new Error(`Tool handler for ${name} must be a function`);
        }
        this.tools.set(name, handler);
    }

    // Get tool definition
    getToolDefinition(name) {
        return this.tools.get(name);
    }

    // Handle tool call
    async handleToolCall(toolCall) {
        try {
            const { id, function: { name, arguments: args } } = toolCall;
            console.log(`[BedrockToolHandler] Handling tool call: ${name}`, { id, args });

            const handler = this.tools.get(name);
            if (!handler) {
                throw new Error(`No handler registered for tool: ${name}`);
            }

            // Parse arguments if they're a string
            const parsedArgs = typeof args === 'string' ? JSON.parse(args) : args;

            // Execute the tool handler
            const result = await handler(parsedArgs);
            
            // Store the result
            this.toolResults.set(id, {
                tool_call_id: id,
                name,
                content: result
            });

            return result;
        } catch (error) {
            console.error('[BedrockToolHandler] Tool call error:', error);
            throw error;
        }
    }

    // Handle multiple tool calls
    async handleToolCalls(toolCalls) {
        try {
            const results = await Promise.all(
                toolCalls.map(toolCall => this.handleToolCall(toolCall))
            );
            return results;
        } catch (error) {
            console.error('[BedrockToolHandler] Multiple tool calls error:', error);
            throw error;
        }
    }

    // Get tool result by ID
    getToolResult(id) {
        return this.toolResults.get(id);
    }

    // Clear tool results
    clearToolResults() {
        this.toolResults.clear();
    }

    // Register a pending tool call
    registerPendingToolCall(id, resolve, reject) {
        this.pendingToolCalls.set(id, { resolve, reject });
    }

    // Resolve a pending tool call
    resolvePendingToolCall(id, result) {
        const pending = this.pendingToolCalls.get(id);
        if (pending) {
            pending.resolve(result);
            this.pendingToolCalls.delete(id);
        }
    }

    // Reject a pending tool call
    rejectPendingToolCall(id, error) {
        const pending = this.pendingToolCalls.get(id);
        if (pending) {
            pending.reject(error);
            this.pendingToolCalls.delete(id);
        }
    }

    // Get all registered tools
    getRegisteredTools() {
        return Array.from(this.tools.keys());
    }

    // Check if a tool is registered
    hasToolRegistered(name) {
        return this.tools.has(name);
    }

    // Get all pending tool calls
    getPendingToolCalls() {
        return Array.from(this.pendingToolCalls.keys());
    }

    // Clear all pending tool calls
    clearPendingToolCalls() {
        this.pendingToolCalls.clear();
    }
}

// Export the tool handler class
if (typeof window !== 'undefined' && !window.BedrockToolHandler) {
    window.BedrockToolHandler = BedrockToolHandler;
}
