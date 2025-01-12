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

    // Role mappers for different models
    static ClaudeMapper = {
        system: "user",
        user: "user",
        assistant: "assistant"
    };

    // Format request body based on model type
    static formatRequestBody(messages, modelConfig, tools = []) {
        if (!Array.isArray(messages)) {
            throw new Error('Invalid messages format');
        }

        if (!modelConfig?.model) {
            throw new Error('Model ID is required');
        }

        const model = modelConfig.model;

        // Format messages based on model type
        if (model.includes("anthropic.claude")) {
            return this.formatClaudeRequest(messages, modelConfig, tools);
        } else if (model.includes("amazon.nova")) {
            return this.formatNovaRequest(messages, modelConfig, tools);
        } else if (model.startsWith("amazon.titan")) {
            return this.formatTitanRequest(messages, modelConfig);
        } else if (model.includes("meta.llama")) {
            return this.formatLlamaRequest(messages, modelConfig);
        } else if (model.includes("mistral.mistral")) {
            return this.formatMistralRequest(messages, modelConfig, tools);
        }

        throw new Error(`Unsupported model: ${model}`);
    }

    // Model-specific formatters
    static formatClaudeRequest(messages, modelConfig, tools = []) {
        // Convert messages to Claude format and handle role mapping
        let formattedMessages = messages.map(message => {
            const content = Array.isArray(message.content) 
                ? message.content.map(item => this.formatContentItem(item)).filter(Boolean)
                : [this.formatContentItem(message.content)].filter(Boolean);

            return {
                role: this.ClaudeMapper[message.role] || message.role || "user",
                content: content
            };
        });

        // Insert semicolon placeholder between consecutive user messages
        for (let i = 0; i < formattedMessages.length - 1; i++) {
            const message = formattedMessages[i];
            const nextMessage = formattedMessages[i + 1];
            
            if (message.role === "user" && nextMessage.role === "user") {
                formattedMessages.splice(i + 1, 0, {
                    role: "assistant",
                    content: ";"
                });
                i++; // Skip the inserted message
            }
        }

        // Ensure first message is from user
        if (formattedMessages[0]?.role === "assistant") {
            formattedMessages.unshift({
                role: "user",
                content: ";"
            });
        }

        // Add system prompt as a user message if present
        if (modelConfig.system_prompt) {
            formattedMessages.unshift({
                role: "user",
                content: modelConfig.system_prompt
            });
        }

        const requestBody = {
            messages: formattedMessages,
            max_tokens: Number(modelConfig.max_tokens || 2000),
            temperature: Number(modelConfig.temperature || 0.7),
            top_p: Number(modelConfig.top_p || 0.9),
            anthropic_version: "bedrock-2023-05-31"
        };

        // Add tools if available
        if (tools.length > 0) {
            requestBody.tools = tools.map(tool => ({
                type: 'function',
                name: tool.function.name,
                display_height_px: 400,
                display_width_px: 600,
                input_schema: {
                    type: 'object',
                    properties: tool.function.parameters.properties,
                    required: tool.function.parameters.required
                },
                description: tool.function.description
            }));
        }

        return requestBody;
    }

    static formatNovaRequest(messages, modelConfig, tools = []) {
        try {
            const systemMessage = messages.find(m => m.role === "system");
            const conversationMessages = messages.filter(m => m.role !== "system");

            // Ensure all text content is properly stringified
            const formatTextContent = (content) => {
                if (Array.isArray(content)) {
                    return content.map(item => {
                        const formatted = this.formatContentItem(item);
                        return formatted?.text || "";
                    }).join("\n");
                }
                return String(content || "");
            };

            const requestBody = {
                messages: conversationMessages.map(message => ({
                    role: String(message.role),
                    content: Array.isArray(message.content)
                        ? message.content.map(item => {
                            const formatted = this.formatContentItem(item);
                            if (!formatted) return null;

                            if (formatted.type === "text") {
                                return { text: String(formatted.text) };
                            } else if (formatted.type === "image") {
                                return { 
                                    image: formatted.source
                                };
                            }
                            return null;
                        }).filter(Boolean)
                        : [{ text: String(message.content) }]
                })),
                temperature: Number(modelConfig.temperature || 0.7),
                top_k: Number(modelConfig.top_k || 50),
                max_tokens: Number(modelConfig.max_tokens || 1000),
                stop_sequences: Array.isArray(modelConfig.stop) ? modelConfig.stop : []
            };

            if (systemMessage) {
                requestBody.system = [{
                    text: formatTextContent(systemMessage.content)
                }];
            }

            // Add tools if available
            if (tools.length > 0) {
                requestBody.toolConfig = {
                    tools: tools.map(tool => ({
                        toolSpec: {
                            name: tool.function.name,
                            description: tool.function.description,
                            inputSchema: {
                                json: {
                                    type: 'object',
                                    properties: tool.function.parameters.properties,
                                    required: tool.function.parameters.required
                                }
                            }
                        }
                    })),
                    toolChoice: { auto: {} }
                };
            }

            // Validate the request body can be properly serialized
            JSON.parse(JSON.stringify(requestBody));
            
            return requestBody;
        } catch (error) {
            console.error('[BedrockAPI] Error formatting Nova request:', error);
            throw new Error('Failed to format Nova request: ' + error.message);
        }
    }

    static formatTitanRequest(messages, modelConfig) {
        const inputText = messages
            .map(message => {
                const content = Array.isArray(message.content)
                    ? message.content.map(item => {
                        const formatted = this.formatContentItem(item);
                        return formatted?.text || "";
                    }).join("\n")
                    : message.content;
                return `${message.role}: ${content}`;
            })
            .join("\n\n");

        return {
            inputText,
            textGenerationConfig: {
                maxTokenCount: Number(modelConfig.max_tokens || 4096),
                temperature: Number(modelConfig.temperature || 0.7),
                stopSequences: modelConfig.stop || []
            }
        };
    }

    static formatLlamaRequest(messages, modelConfig) {
        let prompt = "<|begin_of_text|>";
        
        const systemMessage = messages.find(m => m.role === "system");
        if (systemMessage) {
            const content = Array.isArray(systemMessage.content)
                ? systemMessage.content.map(item => {
                    const formatted = this.formatContentItem(item);
                    return formatted?.text || "";
                }).join("\n")
                : systemMessage.content;
            prompt += `<|start_header_id|>system<|end_header_id|>\n${content}<|eot_id|>`;
        }

        const conversationMessages = messages.filter(m => m.role !== "system");
        for (const message of conversationMessages) {
            const role = message.role === "assistant" ? "assistant" : "user";
            const content = Array.isArray(message.content)
                ? message.content.map(item => {
                    const formatted = this.formatContentItem(item);
                    return formatted?.text || "";
                }).join("\n")
                : message.content;
            prompt += `<|start_header_id|>${role}<|end_header_id|>\n${content}<|eot_id|>`;
        }

        prompt += "<|start_header_id|>assistant<|end_header_id|>";

        return {
            prompt,
            max_gen_len: Number(modelConfig.max_tokens || 512),
            temperature: Number(modelConfig.temperature || 0.7),
            top_p: Number(modelConfig.top_p || 0.9)
        };
    }

    static formatMistralRequest(messages, modelConfig, tools = []) {
        const formattedMessages = messages.map(message => ({
            role: message.role === "system" ? "system" :
                  message.role === "assistant" ? "assistant" : "user",
            content: Array.isArray(message.content)
                ? message.content.map(item => {
                    const formatted = this.formatContentItem(item);
                    return formatted?.text || "";
                }).join("\n")
                : message.content
        }));

        const requestBody = {
            messages: formattedMessages,
            max_tokens: Number(modelConfig.max_tokens || 4096),
            temperature: Number(modelConfig.temperature || 0.7),
            top_p: Number(modelConfig.top_p || 0.9)
        };

        // Add tools if available
        if (tools.length > 0) {
            requestBody.tools = tools.map(tool => ({
                type: 'function',
                function: tool.function
            }));
            requestBody.tool_choice = 'auto';
        }

        return requestBody;
    }
}

// Export the API class
if (typeof window !== 'undefined' && !window.BedrockAPI) {
    window.BedrockAPI = BedrockAPI;
}
