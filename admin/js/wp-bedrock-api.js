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
        const systemMessage = messages.find(m => m.role === "system");
        const conversationMessages = messages.filter(m => m.role !== "system");

        const requestBody = {
            schemaVersion: "messages-v1",
            messages: conversationMessages.map(message => {
                const content = Array.isArray(message.content)
                    ? message.content
                    : [{ text: this.getMessageTextContent(message) }];

                return {
                    role: message.role,
                    content: content.map(item => {
                        if (item.text || typeof item === "string") {
                            return { text: item.text || item };
                        }
                        if (item.image_url?.url) {
                            const { url = "" } = item.image_url;
                            const colonIndex = url.indexOf(":");
                            const semicolonIndex = url.indexOf(";");
                            const comma = url.indexOf(",");

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
                temperature: modelConfig.temperature || 0.7,
                top_p: modelConfig.top_p || 0.9,
                top_k: modelConfig.top_k || 50,
                max_new_tokens: modelConfig.max_tokens || 1000,
                stopSequences: modelConfig.stop || []
            }
        };

        if (systemMessage) {
            requestBody.system = [{
                text: this.getMessageTextContent(systemMessage)
            }];
        }

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
