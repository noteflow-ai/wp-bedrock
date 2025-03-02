/**
 * Bedrock API functionality for formatting and handling requests
 */
class BedrockAPI {
    // Message formatters for different content types
    static formatContentItem(item) {
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
            if (item.type === "image" || item.image_url?.url) {
                const url = item.url || item.image_url?.url;
                if (url.startsWith('data:')) {
                    const [header, data] = url.split(',');
                    const mediaType = header.split(':')[1].split(';')[0];
                    
                    // Validate media type is one of the allowed types
                    if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(mediaType)) {
                        console.error('[BedrockAPI] Unsupported image type:', mediaType);
                        return null;
                    }
                    
                    return {
                        type: "image",
                        source: {
                            type: "base64",
                            media_type: mediaType,
                            data: data
                        }
                    };
                } else {
                    return {
                        type: "image",
                        source: {
                            type: "url",
                            url: url
                        }
                    };
                }
            }

            console.warn('[BedrockAPI] Unknown content item type:', item);
            return null;
        } catch (error) {
            console.error('[BedrockAPI] Error formatting content item:', error);
            return null;
        }
    }

    // Format message for display with tool support
    static formatMessageContent(content, md, modelId = '') {
        if (typeof content === 'string') {
            return md.render(content);
        }

        if (Array.isArray(content)) {
            return content
                .map(item => {
                    switch (item.type) {
                        case 'text':
                            return md.render(item.text);
                        case 'image':
                            const imageUrl = item.image_url?.url || item.url;
                            return `<img src="${imageUrl}" alt="Generated image" class="generated-image">`;
                        case 'tool_call':
                        case 'tool_use':  // Handle Claude's tool_use type
                            return this.formatToolCall(item, modelId);
                        case 'tool_result':
                            return this.formatToolResult(item, modelId);
                        default:
                            return '';
                    }
                })
                .join('');
        }

        // Handle tool-specific content objects
        if (content && typeof content === 'object') {
            if (content.type === 'tool_call' || content.type === 'tool_use') {  // Handle Claude's tool_use type
                return this.formatToolCall(content, modelId);
            }
            if (content.type === 'tool_result') {
                return this.formatToolResult(content, modelId);
            }
        }

        return '';
    }

    // Format tool call for display
    static formatToolCall(toolCall, modelId = '') {
        // Handle Claude's tool_use format
        const name = toolCall.name || toolCall.content?.name || toolCall.tool_name || '';
        const args = toolCall.arguments || toolCall.content?.arguments || toolCall.tool_arguments || {};
        
        return `<div class="tool-message tool-call">
            <div class="tool-header">
                <span class="tool-icon">🔧</span>
                <span class="tool-type">Tool Call</span>
                <span class="tool-name">${name}</span>
            </div>
            <div class="tool-arguments">
                <div class="tool-section-header">Arguments:</div>
                <pre class="tool-code"><code>${JSON.stringify(args, null, 2)}</code></pre>
            </div>
        </div>`;
    }

    // Format tool result for display
    static formatToolResult(toolResult) {
        // Handle Claude's tool_use format
        const name = toolResult.name || toolResult.content?.name || toolResult.tool_name || '';
        const result = toolResult.output || toolResult.result || toolResult.content?.result || toolResult.tool_result || toolResult.content || {};
        
        // Handle search results specially
        if (result.type === 'search_results' && Array.isArray(result.results)) {
            return `<div class="tool-message tool-result">
                <div class="tool-header">
                    <span class="tool-icon">📋</span>
                    <span class="tool-type">Tool Result</span>
                    <span class="tool-name">${name}</span>
                </div>
                <div class="tool-results">
                    <div class="tool-section-header">Search Results for: "${result.query}"</div>
                    ${result.results.map(item => `
                        <div class="search-result">
                            <div class="result-title">${item.title}</div>
                            <div class="result-snippet">${item.snippet}</div>
                            <div class="result-url">${item.url}</div>
                            ${item.time ? `<div class="result-time">${item.time}</div>` : ''}
                        </div>
                    `).join('')}
                </div>
            </div>`;
        }

        // Handle error results
        if (result.error) {
            return `<div class="tool-message tool-result">
                <div class="tool-header">
                    <span class="tool-icon">⚠️</span>
                    <span class="tool-type">Tool Result</span>
                    <span class="tool-name">${name}</span>
                </div>
                <div class="tool-error">
                    <div class="tool-section-header">Error:</div>
                    <pre class="tool-code error"><code>${result.error}</code></pre>
                </div>
            </div>`;
        }

        // Default result display
        return `<div class="tool-message tool-result">
            <div class="tool-header">
                <span class="tool-icon">📋</span>
                <span class="tool-type">Tool Result</span>
                <span class="tool-name">${name}</span>
            </div>
            <div class="tool-results">
                <div class="tool-section-header">Result:</div>
                <pre class="tool-code"><code>${JSON.stringify(result, null, 2)}</code></pre>
            </div>
        </div>`;
    }

    // Role mappers for different models
    static ClaudeMapper = {
        assistant: "assistant",
        user: "user",
        system: "user",
        tool: "assistant" // Map tool responses as assistant messages
    };

    static MistralMapper = {
        system: "system",
        user: "user",
        assistant: "assistant"
    };

    // Normalize messages to ensure model-specific requirements are met with enhanced tool handling
    static normalizeMessages(messages, model) {
        // Filter out messages with empty content and ensure valid content format
        messages = messages.map(msg => {
            // Ensure content is in correct format
            if (!msg.content) {
                msg.content = [{ type: 'text', text: ';' }];
            } else if (typeof msg.content === 'string') {
                msg.content = [{ type: 'text', text: msg.content || ';' }];
            } else if (Array.isArray(msg.content) && msg.content.length === 0) {
                msg.content = [{ type: 'text', text: ';' }];
            } else if (Array.isArray(msg.content)) {
                msg.content = msg.content.map(item => {
                    if (typeof item === 'string') {
                        return { type: 'text', text: item || ';' };
                    }
                    if (item.type === 'text') {
                        return { type: 'text', text: item.text || ';' };
                    }
                    return item;
                });
            }
            return msg;
        });

        // Group messages by role
        const systemMessages = messages.filter(msg => msg.role === 'system');
        const userMessages = messages.filter(msg => msg.role === 'user');
        const assistantMessages = messages.filter(msg => msg.role === 'assistant');
        
        let normalizedMessages = [];

        // Handle model-specific requirements
        if (model.includes('amazon.nova')) {
            // Nova requires first turn to be user
            if (systemMessages.length > 0) {
                normalizedMessages.push(...systemMessages);
            }
            
            // Ensure we have at least one user message
            if (userMessages.length === 0) {
                normalizedMessages.push({
                    role: 'user',
                    content: [{ type: 'text', text: 'Hello' }]
                });
            } else {
                // Interleave user and assistant messages
                let messageIndex = 0;
                while (messageIndex < Math.max(userMessages.length, assistantMessages.length)) {
                    if (messageIndex < userMessages.length) {
                        normalizedMessages.push(userMessages[messageIndex]);
                    }
                    if (messageIndex < assistantMessages.length) {
                        const assistantMsg = assistantMessages[messageIndex];
                        // Ensure assistant messages have non-empty content
                        if (!assistantMsg.content || (Array.isArray(assistantMsg.content) && assistantMsg.content.length === 0)) {
                            normalizedMessages.push({
                                role: 'assistant',
                                content: [{ type: 'text', text: ';' }]
                            });
                        } else {
                            normalizedMessages.push(assistantMsg);
                        }
                    }
                    messageIndex++;
                }
            }
        } else if (model.includes('anthropic.claude')) {
            // Claude requires alternating user/assistant messages
            if (systemMessages.length > 0) {
                normalizedMessages.push(...systemMessages.map(msg => ({
                    ...msg,
                    role: 'user', // Claude treats system messages as user messages
                    content: Array.isArray(msg.content) ? msg.content : [{ type: 'text', text: msg.content || ';' }]
                })));
            }
            
            let messageIndex = 0;
            while (messageIndex < Math.max(userMessages.length, assistantMessages.length)) {
                if (messageIndex < userMessages.length) {
                    const userMsg = userMessages[messageIndex];
                    normalizedMessages.push({
                        ...userMsg,
                        content: Array.isArray(userMsg.content) ? userMsg.content : [{ type: 'text', text: userMsg.content || ';' }]
                    });
                }
                if (messageIndex < assistantMessages.length) {
                    const assistantMsg = assistantMessages[messageIndex];
                    normalizedMessages.push({
                        ...assistantMsg,
                        content: Array.isArray(assistantMsg.content) ? 
                            (assistantMsg.content.length > 0 ? assistantMsg.content : [{ type: 'text', text: ';' }]) :
                            [{ type: 'text', text: assistantMsg.content || ';' }]
                    });
                } else if (messageIndex < userMessages.length - 1) {
                    // Add non-empty assistant response between consecutive user messages
                    normalizedMessages.push({
                        role: 'assistant',
                        content: [{ type: 'text', text: ';' }]
                    });
                }
                messageIndex++;
            }
        } else {
            // Default handling for other models
            normalizedMessages = messages.map(msg => ({
                ...msg,
                content: Array.isArray(msg.content) ? 
                    (msg.content.length > 0 ? msg.content : [{ type: 'text', text: ';' }]) :
                    [{ type: 'text', text: msg.content || ';' }]
            }));
        }

        return normalizedMessages;
    }

    // Format tool use based on model type
    static formatToolUse(toolCall, modelId, toolId = null) {
        if (!toolId) {
            toolId = `call_${Date.now()}`;
        }

        if (modelId.includes('anthropic.claude')) {
            return {
                role: 'assistant',
                content: [{
                    type: 'tool_use',
                    id: toolId,
                    name: toolCall.name,
                    input: typeof toolCall.arguments === 'string' ? 
                        JSON.parse(toolCall.arguments) : toolCall.arguments
                }]
            };
        }

        if (modelId.includes('mistral.mistral')) {
            return {
                role: 'assistant',
                content: '',
                tool_calls: [{
                    id: toolId,
                    function: {
                        name: toolCall.name,
                        arguments: typeof toolCall.arguments === 'string' ? 
                            toolCall.arguments : JSON.stringify(toolCall.arguments)
                    }
                }]
            };
        }

        if (modelId.includes('amazon.nova')) {
            return {
                role: 'assistant',
                content: [{
                    toolUse: {
                        toolUseId: toolId,
                        name: toolCall.name,
                        input: typeof toolCall.arguments === 'string' ? 
                            JSON.parse(toolCall.arguments) : toolCall.arguments
                    }
                }]
            };
        }

        return null;
    }

    // Format tool result based on model type
    static formatToolResult(toolResult, modelId) {
        const output = toolResult.output || toolResult.content;
        
        if (modelId.includes('anthropic.claude')) {
            return {
                role: 'user',
                content: [{
                    type: 'tool_result',
                    tool_use_id: toolResult.tool_call_id,
                    content: { output }
                }]
            };
        }

        if (modelId.includes('mistral.mistral')) {
            return {
                role: 'tool',
                tool_call_id: toolResult.tool_call_id,
                content: JSON.stringify({ output })
            };
        }

        if (modelId.includes('amazon.nova')) {
            return {
                role: 'user',
                content: [{
                    toolResult: {
                        toolUseId: toolResult.tool_call_id,
                        content: [{
                            json: {
                                content: { output }
                            }
                        }]
                    }
                }]
            };
        }

        return null;
    }

    // Format text content based on model type
    static formatTextContent(text, modelId) {
        if (modelId.includes('anthropic.claude')) {
            return {
                role: 'assistant',
                content: [{
                    type: 'text',
                    text: text
                }]
            };
        }

        return {
            role: 'assistant',
            content: text
        };
    }

    // Format request body based on model type
    static formatRequestBody(messages, modelConfig, tools = []) {
        if (!Array.isArray(messages)) {
            throw new Error('Invalid messages format');
        }

        if (!modelConfig?.model) {
            throw new Error('Model ID is required');
        }

        const model = modelConfig.model;

        // Filter out empty messages first
        messages = messages.filter(msg => {
            if (!msg.content) return false;
            if (Array.isArray(msg.content) && msg.content.length === 0) return false;
            if (typeof msg.content === 'string' && !msg.content.trim()) return false;
            return true;
        });

        // When using tools, ensure they are properly configured for each model
        if (tools?.length > 0) {
            // For Claude, keep initial user message and tool results
            if (model.includes("anthropic.claude")) {
                const userMessages = messages.filter(msg => msg.role === 'user');
                const toolResults = messages.filter(msg => 
                    msg.content?.some?.(item => 
                        item.type === 'tool_result' || 
                        item.type === 'tool_use' ||
                        (item.type === 'text' && item.text?.includes('tool_result'))
                    )
                );
                
                if (userMessages.length === 0) {
                    throw new Error('At least one user message is required');
                }

                // Keep only the first user message and any tool results
                messages = [
                    userMessages[0],
                    ...toolResults
                ];
            }
            // For Mistral, ensure tool calls are properly formatted
            else if (model.includes("mistral.mistral")) {
                const toolCalls = messages.filter(msg => msg.tool_calls);
                const toolResults = messages.filter(msg => msg.role === 'tool');
                messages = [
                    ...messages.filter(msg => !msg.tool_calls && msg.role !== 'tool'),
                    ...toolCalls,
                    ...toolResults
                ];
            }
            // For Nova, ensure toolUse and toolResult are properly formatted
            else if (model.includes("amazon.nova")) {
                const toolUses = messages.filter(msg => 
                    msg.content?.some?.(item => item.toolUse)
                );
                const toolResults = messages.filter(msg => 
                    msg.content?.some?.(item => item.toolResult)
                );
                messages = [
                    ...messages.filter(msg => 
                        !msg.content?.some?.(item => item.toolUse || item.toolResult)
                    ),
                    ...toolUses,
                    ...toolResults
                ];
            }
        }
        
        // Normalize messages
        let normalizedMessages = this.normalizeMessages(messages, model);
        
        // Format final request based on model type
        if (model.includes("anthropic.claude")) {
            return this.formatClaudeRequest(normalizedMessages, modelConfig, tools);
        } else if (model.includes("amazon.nova")) {
            return this.formatNovaRequest(normalizedMessages, modelConfig, tools);
        } else if (model.startsWith("amazon.titan")) {
            return this.formatTitanRequest(normalizedMessages, modelConfig);
        } else if (model.includes("meta.llama")) {
            return this.formatLlamaRequest(normalizedMessages, modelConfig);
        } else if (model.includes("mistral.mistral")) {
            return this.formatMistralRequest(normalizedMessages, modelConfig, tools);
        }

        throw new Error(`Unsupported model: ${model}`);
    }

    // Model-specific formatters
    static formatClaudeRequest(messages, modelConfig, tools = []) {
        // Filter out empty messages first
        messages = messages.filter(msg => {
            if (!msg.content) return false;
            if (Array.isArray(msg.content) && msg.content.length === 0) return false;
            if (typeof msg.content === "string" && !msg.content.trim()) return false;
            return true;
        });

        const keys = ["system", "user"];
        // roles must alternate between "user" and "assistant" in claude, so add a fake assistant message between two user messages
        for (let i = 0; i < messages.length - 1; i++) {
            const message = messages[i];
            const nextMessage = messages[i + 1];

            if (keys.includes(message.role) && keys.includes(nextMessage.role)) {
                messages[i] = [
                    message,
                    {
                        role: "assistant",
                        content: [{ type: "text", text: ";" }]
                    }
                ];
            }
        }

        const prompt = messages
            .flat()
            .map(v => {
                const { role, content } = v;
                const insideRole = this.ClaudeMapper[role] || "user";

                // Ensure content is always an array of objects with type and text/image_url
                let formattedContent;
                if (typeof content === "string") {
                    formattedContent = [{ type: "text", text: content || ";" }];
                } else if (Array.isArray(content)) {
                    formattedContent = content.map(item => {
                        if (typeof item === "string") {
                            return { type: "text", text: item || ";" };
                        }
                        if (item.type === "text") {
                            return { type: "text", text: item.text || ";" };
                        }
                        if (item.image_url) {
                            const { url = "" } = item.image_url;
                            const colonIndex = url.indexOf(":");
                            const semicolonIndex = url.indexOf(";");
                            const comma = url.indexOf(",");

                            const mimeType = url.slice(colonIndex + 1, semicolonIndex);
                            const encodeType = url.slice(semicolonIndex + 1, comma);
                            const data = url.slice(comma + 1);

                            return {
                                type: "image",
                                source: {
                                    type: encodeType,
                                    media_type: mimeType,
                                    data
                                }
                            };
                        }
                        return { type: "text", text: ";" };
                    });
                } else {
                    formattedContent = [{ type: "text", text: ";" }];
                }

                return {
                    role: insideRole,
                    content: formattedContent
                };
            });

        // Ensure first message is from user
        if (prompt[0]?.role === "assistant") {
            prompt.unshift({
                role: "user",
                content: [{ type: "text", text: ";" }]
            });
        }

        const requestBody = {
            anthropic_version: modelConfig.anthropic_version || "bedrock-2023-05-31",
            max_tokens: modelConfig.max_tokens || 2000,
            messages: prompt,
            temperature: modelConfig.temperature || 0.7,
            top_p: modelConfig.top_p || 0.9,
            top_k: modelConfig.top_k || 5
        };

        // Add tools if available for Claude models
        if (tools && tools.length > 0) {
            requestBody.tools = tools.map(tool => {
                // Handle both direct Claude format and function format
                if (tool.name && tool.input_schema) {
                    return tool;
                }
                return {
                    name: tool.function?.name || tool.name || "",
                    description: tool.function?.description || tool.description || "",
                    input_schema: tool.function?.parameters || tool.input_schema || {}
                };
            });
        }

        return requestBody;
    }

    static formatNovaRequest(messages, modelConfig, tools = []) {
        // Extract system message and conversation messages
        const systemMessage = messages.find(m => m.role === "system");
        const conversationMessages = messages.filter(m => m.role !== "system");

        // Build request body according to Nova schema
        const requestBody = {
            schemaVersion: "messages-v1",
            messages: conversationMessages.map(message => {
                const content = Array.isArray(message.content) ? 
                    message.content : 
                    [{ text: this.getMessageTextContent(message) }];

                return {
                    role: message.role,
                    content: content.map(item => {
                        // Handle text content
                        if (item.text || typeof item === "string") {
                            return { text: item.text || item };
                        }
                        // Handle image content
                        if (item.image_url?.url) {
                            const { url = "" } = item.image_url;
                            const colonIndex = url.indexOf(":");
                            const semicolonIndex = url.indexOf(";");
                            const comma = url.indexOf(",");

                            // Extract format from mime type
                            const mimeType = url.slice(colonIndex + 1, semicolonIndex);
                            const format = mimeType.split("/")[1];
                            const data = url.slice(comma + 1);

                            return {
                                image: {
                                    format,
                                    source: {
                                        bytes: data
                                    }
                                }
                            };
                        }
                        return item;
                    })
                };
            }),
            inferenceConfig: {
                temperature: Number(modelConfig.temperature || 0.8),
                top_p: Number(modelConfig.top_p || 0.9),
                top_k: Number(modelConfig.top_k || 50),
                max_new_tokens: Number(modelConfig.max_tokens || 1000),
                stopSequences: modelConfig.stop || []
            }
        };

        // Add system message if present
        if (systemMessage) {
            requestBody.system = [{
                text: this.getMessageTextContent(systemMessage)
            }];
        }

            // Add tools if available
            if (tools && tools.length > 0) {
                // Format tools for Nova
                const validTools = tools.map(tool => {
                    // Handle both direct Nova format and function format
                    if (tool.toolSpec) {
                        return tool; // Already in Nova format
                    }
                    return {
                        toolSpec: {
                            name: tool.name || tool.function?.name || "",
                            description: tool.description || tool.function?.description || "",
                            inputSchema: {
                                json: tool.function?.parameters || {
                                    type: "object",
                                    properties: {},
                                    required: []
                                }
                            }
                        }
                    };
                });

                if (validTools.length > 0) {
                    requestBody.toolConfig = {
                        tools: validTools,
                        toolChoice: { auto: {} }
                    };
                }
            }

        return requestBody;
    }

    // Message content preparation and extraction with tool support
    static prepareMessageContent(messageText, imagePreview, toolData = null) {
        const content = [];

        // Add text content if present
        if (messageText) {
            content.push({ type: 'text', text: messageText });
        }

        // Add image content if present
        if (imagePreview) {
            if (!messageText) {
                content.push({ type: 'text', text: 'Here is an image:' });
            }
            content.push({ type: 'image', image_url: { url: imagePreview } });
        }

        // Add tool data if present
        if (toolData) {
            content.push(this.normalizeToolMessage(toolData));
        }

        // Always return an array for Nova format consistency
        return content;
    }

    // Normalize tool message format
    static normalizeToolMessage(message) {
        if (typeof message.content === 'string') {
            try {
                message.content = JSON.parse(message.content);
            } catch (e) {
                console.warn('[BedrockAPI] Failed to parse tool message content:', e);
            }
        }

        if (Array.isArray(message.content)) {
            message.content = message.content[0];
        }

        const content = message.content || {};
        
        if (content.type === 'tool_call') {
            return {
                ...message,
                content: {
                    type: 'tool_call',
                    name: content.tool || content.name,
                    arguments: content.arguments || {}
                }
            };
        }

        if (content.type === 'tool_result') {
            return {
                ...message,
                content: {
                    type: 'tool_result',
                    name: content.tool || content.name,
                    result: content.result
                }
            };
        }

        // Handle legacy format
        if (content.tool || content.name) {
            return {
                ...message,
                content: {
                    type: message.role === 'tool' ? 'tool_result' : 'tool_call',
                    name: content.tool || content.name,
                    ...(message.role === 'tool' ? 
                        { result: content.result || content } :
                        { arguments: content.arguments || {} }
                    )
                }
            };
        }

        return message;
    }

    static getMessageTextContent(message) {
        if (typeof message.content === "string") {
            return message.content;
        }
        if (Array.isArray(message.content)) {
            return message.content
                .filter(item => item.text || typeof item === "string")
                .map(item => item.text || item)
                .join("\n");
        }
        return "";
    }

    static prepareRequestMessages(config, messageHistory, selectedTools = []) {
        // Start with system prompt if configured
        let messages = [];
        if (config.default_system_prompt) {
            messages.push({
                role: 'system',
                content: [{ type: 'text', text: config.default_system_prompt }]
            });
        }

        // Get user and assistant messages
        const userMessages = messageHistory.filter(msg => msg.role === 'user');
        const assistantMessages = messageHistory.filter(msg => msg.role === 'assistant');

        // Ensure we have at least one user message
        if (userMessages.length === 0) {
            return this.formatRequestBody(
                messages,
                {
                    model: config.default_model,
                    temperature: Number(config.default_temperature || 0.7),
                    max_tokens: 2000,
                    top_p: 0.9,
                    anthropic_version: "bedrock-2023-05-31"
                },
                selectedTools
            );
        }

        // Build conversation in proper order
        let messageIndex = 0;
        while (messageIndex < Math.max(userMessages.length, assistantMessages.length)) {
            // Always add user message first in each turn
            if (messageIndex < userMessages.length) {
                messages.push(userMessages[messageIndex]);
            }
            // Then add assistant response if available
            if (messageIndex < assistantMessages.length) {
                messages.push(assistantMessages[messageIndex]);
            }
            messageIndex++;
        }

        return this.formatRequestBody(
            messages,
            {
                model: config.default_model,
                temperature: Number(config.default_temperature || 0.7),
                max_tokens: 2000,
                top_p: 0.9,
                anthropic_version: "bedrock-2023-05-31"
            },
            selectedTools
        );
    }

    static formatTitanRequest(messages, modelConfig) {
        const inputText = messages
            .map(message => `${message.role}: ${this.getMessageTextContent(message)}`)
            .join("\n\n");

        return {
            inputText,
            textGenerationConfig: {
                maxTokenCount: modelConfig.max_tokens,
                temperature: modelConfig.temperature,
                stopSequences: []
            }
        };
    }

    static formatLlamaRequest(messages, modelConfig) {
        let prompt = "<|begin_of_text|>";

        const systemMessage = messages.find(m => m.role === "system");
        if (systemMessage) {
            prompt += `<|start_header_id|>system<|end_header_id|>\n${this.getMessageTextContent(systemMessage)}<|eot_id|>`;
        }

        const conversationMessages = messages.filter(m => m.role !== "system");
        for (const message of conversationMessages) {
            const role = message.role === "assistant" ? "assistant" : "user";
            prompt += `<|start_header_id|>${role}<|end_header_id|>\n${this.getMessageTextContent(message)}<|eot_id|>`;
        }

        prompt += "<|start_header_id|>assistant<|end_header_id|>";

        return {
            prompt,
            max_gen_len: modelConfig.max_tokens || 512,
            temperature: modelConfig.temperature || 0.7,
            top_p: modelConfig.top_p || 0.9
        };
    }

    static formatMistralRequest(messages, modelConfig, tools = []) {
        const formattedMessages = messages.map(message => ({
            role: this.MistralMapper[message.role] || "user",
            content: this.getMessageTextContent(message)
        }));

        const requestBody = {
            messages: formattedMessages,
            max_tokens: modelConfig.max_tokens || 4096,
            temperature: modelConfig.temperature || 0.7,
            top_p: modelConfig.top_p || 0.9
        };

        // Add tools if available
        if (tools && tools.length > 0) {
            // Format tools for Mistral
            const validTools = tools.map(tool => {
                // Handle both direct Mistral format and function format
                if (tool.type === "function" && tool.function) {
                    return tool;
                }
                return {
                    type: "function",
                    function: {
                        name: tool.function?.name || tool.name || "",
                        description: tool.function?.description || tool.description || "",
                        parameters: tool.function?.parameters || tool.parameters || {}
                    }
                };
            });

            if (validTools.length > 0) {
                requestBody.tools = validTools;
                requestBody.tool_choice = "auto";
            }
        }

        return requestBody;
    }
}

// Export the API class
if (typeof window !== 'undefined' && !window.BedrockAPI) {
    window.BedrockAPI = BedrockAPI;
}
