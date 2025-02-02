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
                        case 'tool_use':  // Handle Claude's tool_use type
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
            if (content.type === 'tool_call' || content.type === 'tool_use') {  // Handle Claude's tool_use type
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
        // Handle Claude's tool_use format
        const name = toolCall.name || toolCall.content?.name || toolCall.tool_name || '';
        const args = toolCall.arguments || toolCall.content?.arguments || toolCall.tool_arguments || {};
        
        return `<div class="tool-message tool-call">
            <div class="tool-header">
                <span class="tool-type">Tool Call</span>
                <span class="tool-name">${name}</span>
            </div>
            <pre class="tool-content"><code>${JSON.stringify(args, null, 2)}</code></pre>
        </div>`;
    }

    // Format tool result for display
    static formatToolResult(toolResult) {
        // Handle Claude's tool_use format
        const name = toolResult.name || toolResult.content?.name || toolResult.tool_name || '';
        const result = toolResult.output || toolResult.result || toolResult.content?.result || toolResult.tool_result || {};
        
        return `<div class="tool-message tool-result">
            <div class="tool-header">
                <span class="tool-type">Tool Result</span>
                <span class="tool-name">${name}</span>
            </div>
            <pre class="tool-content"><code>${JSON.stringify(result, null, 2)}</code></pre>
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
        
        // Normalize and filter messages based on model
        let normalizedMessages = this.normalizeMessages(messages, model);
        
        // For Claude, ensure the last message is not from assistant when using tools
        if (model.includes("anthropic.claude") && tools?.length > 0) {
            if (normalizedMessages[normalizedMessages.length - 1]?.role === 'assistant') {
                normalizedMessages = normalizedMessages.slice(0, -1);
            }
        }
        
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
        // First filter out empty messages and format content
        const validMessages = messages.filter(msg => {
            if (!msg.content) return false;
            if (typeof msg.content === "string" && !msg.content.trim()) return false;
            if (Array.isArray(msg.content) && msg.content.length === 0) return false;
            return true;
        });

        // Format each message's content
        const formattedMessages = validMessages.map(msg => {
            const { role, content } = msg;
            const mappedRole = this.ClaudeMapper[role] || "user";

            // Convert array content to string for Claude
            if (Array.isArray(content)) {
                const textContent = content
                    .filter(item => {
                        if (item.type === "text" || item.text) return true;
                        if (item.type === "tool_use") return true;
                        if (item.type === "tool_result") return true;
                        return false;
                    })
                    .map(item => {
                        if (item.type === "text" || item.text) {
                            return item.text || "";
                        }
                        if (item.type === "tool_use") {
                            return `Using tool: ${item.name}\nInput: ${JSON.stringify(item.input)}`;
                        }
                        if (item.type === "tool_result") {
                            const output = item.content?.output || item.content;
                            return `Tool result: ${JSON.stringify({ output })}`;
                        }
                        return "";
                    })
                    .filter(text => text.trim())
                    .join("\n");
                
                if (textContent) {
                    return {
                        role: mappedRole,
                        content: textContent
                    };
                }
                return null;
            }

            // Handle string content
            if (typeof content === "string") {
                return {
                    role: mappedRole,
                    content: content
                };
            }

            // Handle object content
            if (content && typeof content === "object") {
                if (content.type === "text" || content.text) {
                    const text = content.text || content.content;
                    if (text && text.trim()) {
                        return {
                            role: mappedRole,
                            content: text
                        };
                    }
                }

                // Handle tool use
                if (content.type === "tool_use") {
                    return {
                        role: mappedRole,
                        content: `Using tool: ${content.name}\nInput: ${JSON.stringify(content.input)}`
                    };
                }

                // Handle tool result
                if (content.type === "tool_result") {
                    return {
                        role: mappedRole,
                        content: `Tool result: ${JSON.stringify(content.content)}`
                    };
                }
            }

            return null;
        }).filter(msg => msg !== null);

        // Ensure messages alternate between user and assistant
        const finalMessages = [];
        let lastRole = null;

        for (const msg of formattedMessages) {
            if (msg.role === lastRole) {
                // If same role appears twice, add an empty response from the other role
                finalMessages.push({
                    role: msg.role === "user" ? "assistant" : "user",
                    content: "Continuing the conversation..."
                });
            }
            finalMessages.push(msg);
            lastRole = msg.role;
        }

        // Ensure first message is from user
        if (finalMessages[0]?.role === "assistant") {
            finalMessages.unshift({
                role: "user",
                content: "Starting the conversation..."
            });
        }

        // Ensure required Claude parameters are present
        const requestBody = {
            messages: finalMessages,
            max_tokens: Number(modelConfig.max_tokens || 2000),
            temperature: Number(modelConfig.temperature || 0.7),
            top_p: Number(modelConfig.top_p || 0.9),
            anthropic_version: modelConfig.anthropic_version || "bedrock-2023-05-31"
        };

        // Add tools if they exist and are properly formatted
        if (tools && tools.length > 0) {
            // Filter and format tools based on model type
            if (modelConfig.model.includes('anthropic.claude')) {
                // Claude format: only name and input_schema
                const validTools = tools.filter(tool => 
                    tool && tool.name && tool.input_schema && typeof tool.input_schema === 'object'
                );
                if (validTools.length > 0) {
                    requestBody.tools = validTools;
                }
            } else if (modelConfig.model.includes('mistral.mistral')) {
                // Mistral format: type, function with name, description, parameters
                const validTools = tools.filter(tool => 
                    tool && tool.type === 'function' && tool.function?.name && tool.function?.parameters
                );
                if (validTools.length > 0) {
                    requestBody.tools = validTools;
                }
            } else if (modelConfig.model.includes('amazon.nova')) {
                // Nova format: toolSpec with name, description, inputSchema
                const validTools = tools.filter(tool => 
                    tool && tool.toolSpec?.name && tool.toolSpec?.inputSchema
                );
                if (validTools.length > 0) {
                    requestBody.toolConfig = {
                        tools: validTools,
                        toolChoice: { auto: {} }
                    };
                }
            }
        }

        // Log request for debugging
        console.log('[BedrockAPI] Claude request:', JSON.stringify(requestBody, null, 2));

        return requestBody;
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
