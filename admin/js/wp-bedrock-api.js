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
    static formatMessageContent(content, md) {
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
                            return this.formatToolCall(item);
                        case 'tool_result':
                            return this.formatToolResult(item);
                        default:
                            return '';
                    }
                })
                .join('');
        }

        // Handle tool-specific content objects
        if (content && typeof content === 'object') {
            if (content.type === 'tool_call') {
                return this.formatToolCall(content);
            }
            if (content.type === 'tool_result') {
                return this.formatToolResult(content);
            }
        }

        return '';
    }

    // Format tool call for display
    static formatToolCall(toolCall) {
        return `<div class="tool-message tool-call">
            <div class="tool-header">
                <span class="tool-type">Tool Call</span>
                <span class="tool-name">${toolCall.name || toolCall.content?.name || ''}</span>
            </div>
            <pre class="tool-content"><code>${JSON.stringify(toolCall.arguments || toolCall.content?.arguments || {}, null, 2)}</code></pre>
        </div>`;
    }

    // Format tool result for display
    static formatToolResult(toolResult) {
        return `<div class="tool-message tool-result">
            <div class="tool-header">
                <span class="tool-type">Tool Result</span>
                <span class="tool-name">${toolResult.name || toolResult.content?.name || ''}</span>
            </div>
            <pre class="tool-content"><code>${JSON.stringify(toolResult.result || toolResult.content?.result || {}, null, 2)}</code></pre>
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
        // Group messages by role
        const systemMessages = messages.filter(msg => msg.role === 'system');
        const userMessages = messages.filter(msg => msg.role === 'user');
        const assistantMessages = messages.filter(msg => msg.role === 'assistant');
        const toolMessages = messages.filter(msg => msg.role === 'tool');

        // Process tool messages to ensure proper format
        const processedToolMessages = toolMessages.map(msg => {
            if (!msg.turnIndex) {
                // If no turn index, try to infer it from message position
                msg.turnIndex = Math.floor(messages.indexOf(msg) / 2);
            }
            return this.normalizeToolMessage(msg);
        });
        
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
                        normalizedMessages.push(assistantMessages[messageIndex]);
                    }
                    // Add any tool messages for this turn
                    const turnToolMessages = toolMessages.filter(msg => msg.turnIndex === messageIndex);
                    normalizedMessages.push(...turnToolMessages);
                    messageIndex++;
                }
            }
        } else if (model.includes('anthropic.claude')) {
            // Claude requires alternating user/assistant messages
            if (systemMessages.length > 0) {
                normalizedMessages.push(...systemMessages.map(msg => ({
                    ...msg,
                    role: 'user' // Claude treats system messages as user messages
                })));
            }
            
            let messageIndex = 0;
            while (messageIndex < Math.max(userMessages.length, assistantMessages.length)) {
                if (messageIndex < userMessages.length) {
                    normalizedMessages.push(userMessages[messageIndex]);
                }
                if (messageIndex < assistantMessages.length) {
                    normalizedMessages.push(assistantMessages[messageIndex]);
                } else if (messageIndex < userMessages.length - 1) {
                    // Add empty assistant response between consecutive user messages
                    normalizedMessages.push({
                        role: 'assistant',
                        content: [{ type: 'text', text: ';' }]
                    });
                }
                messageIndex++;
            }
        } else {
            // Default handling for other models
            normalizedMessages = [...messages];
        }

        return normalizedMessages;
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
        
        // Normalize messages based on model requirements
        const normalizedMessages = this.normalizeMessages(messages, model);

        // Format messages based on model type
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
                        content: ";"
                    }
                ];
            }
        }

        const formattedMessages = messages
            .flat()
            .filter(v => {
                if (!v.content) return false;
                if (typeof v.content === "string" && !v.content.trim()) return false;
                return true;
            })
            .map(v => {
                const { role, content } = v;
                const mappedRole = this.ClaudeMapper[role] || "user";

                if (typeof content === "string") {
                    return {
                        role: mappedRole,
                        content: content
                    };
                }

                // Handle array content (text and images)
                return {
                    role: mappedRole,
                    content: content
                        .filter(item => item.image_url || item.text)
                        .map(({ type, text, image_url }) => {
                            if (type === "text" || text) {
                                return {
                                    type: "text",
                                    text: text
                                };
                            }

                            if (image_url?.url) {
                                const { url = "" } = image_url;
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
                            return null;
                        })
                        .filter(Boolean)
                };
            });

        // Ensure first message is from user
        if (formattedMessages[0]?.role === "assistant") {
            formattedMessages.unshift({
                role: "user",
                content: ";"
            });
        }

        return {
                messages: formattedMessages,
                max_tokens: Number(modelConfig.max_tokens || 2000),
                temperature: Number(modelConfig.temperature || 0.7),
                top_p: Number(modelConfig.top_p || 0.9),
                anthropic_version: "bedrock-2023-05-31",
                ...(tools.length > 0 && {
                    tools: tools.map(tool => ({
                        name: (tool?.function?.name || "").toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_-]/g, ''),
                        description: tool?.function?.description || "",
                        input_schema: tool?.function?.parameters || {}
                    }))
                })
            
        };
    }

    static formatNovaRequest(messages, modelConfig, tools = []) {
        // Extract system message and conversation messages
        const systemMessage = messages.find(m => m.role === "system");
        const conversationMessages = messages.filter(m => m.role !== "system");

        // Build request body according to Nova schema
        const requestBody = {};

        // Add system message if present
        if (systemMessage) {
            requestBody.system = [{
                text: this.getMessageTextContent(systemMessage)
            }];
        }

        // Format conversation messages
        requestBody.messages = conversationMessages.map(message => {
            let formattedContent;
            
            // Handle array content
            if (Array.isArray(message.content)) {
                formattedContent = message.content.map(item => {
                    // Handle string items
                    if (typeof item === "string") {
                        return { text: item };
                    }
                    
                    // Handle text content
                    if (item.text || item.type === "text") {
                        return { text: item.text || "" };
                    }
                    
                    // Handle image content
                    if (item.image_url?.url) {
                        const { url = "" } = item.image_url;
                        if (url.startsWith('data:')) {
                            const [header, data] = url.split(',');
                            const mimeType = header.split(':')[1].split(';')[0];
                            const format = mimeType.split('/')[1];
                            return {
                                image: {
                                    format: format,
                                    source: {
                                        bytes: data
                                    }
                                }
                            };
                        }
                    }
                    return null;
                }).filter(Boolean);
            } else {
                // Handle string content with proper encoding
                const messageText = typeof message.content === "string" ? 
                    message.content : 
                    this.getMessageTextContent(message);
                
                formattedContent = [{
                    text: messageText || " " // Ensure non-empty content with proper encoding
                }];
            }

            // Ensure we have valid content
            if (!formattedContent || formattedContent.length === 0) {
                formattedContent = [{ text: " " }]; // Space to ensure non-empty content
            }

            return {
                role: message.role,
                content: formattedContent
            };
        });

        // Add inference configuration
        requestBody.inferenceConfig = {
            temperature: Number(modelConfig.temperature || 0.8),
            top_p: Number(modelConfig.top_p || 0.9),
            top_k: Number(modelConfig.top_k || 50),
            max_new_tokens: Number(modelConfig.max_tokens || 1000),
            stopSequences: modelConfig.stop || []
        };

        // Log the request for debugging
        console.log('[BedrockAPI] Nova request:', JSON.stringify(requestBody, null, 2));

        // Add tool configuration if tools are present
        if (tools.length > 0) {
            requestBody.toolConfig = {
                tools: tools.map(tool => ({
                    toolSpec: {
                        name: tool?.function?.name || "",
                        description: tool?.function?.description || "",
                        inputSchema: {
                            json: tool?.function?.parameters || {}
                        }
                    }
                })),
                toolChoice: { auto: {} }
            };
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
        const toolMessages = messageHistory.filter(msg => msg.role === 'tool');

        // Ensure we have at least one user message
        if (userMessages.length === 0) {
            return this.formatRequestBody(
                messages,
                {
                    model: config.default_model,
                    temperature: Number(config.default_temperature || 0.7)
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
            // Add any tool messages for this turn
            const turnToolMessages = toolMessages.filter(msg => msg.turnIndex === messageIndex);
            messages.push(...turnToolMessages);
            messageIndex++;
        }

        return this.formatRequestBody(
            messages,
            {
                model: config.default_model,
                temperature: Number(config.default_temperature || 0.7)
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
    }
}

// Export the API class
if (typeof window !== 'undefined' && !window.BedrockAPI) {
    window.BedrockAPI = BedrockAPI;
}
