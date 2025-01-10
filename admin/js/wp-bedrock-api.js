window.BedrockAPI = class BedrockAPI {
    static formatContentItem(item) {
        if (item.text || typeof item === "string") {
            return { type: "text", text: item.text || item };
        }
        if (item.image_url?.url) {
            const url = item.image_url.url;
            const colonIndex = url.indexOf(":");
            const semicolonIndex = url.indexOf(";");
            const comma = url.indexOf(",");

            if (colonIndex >= 0 && semicolonIndex >= 0 && comma >= 0) {
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
        }
        return null;
    }

    static formatRequestBody(messages, modelConfig, tools = []) {
        const model = modelConfig.model;

        // Claude models
        if (model.includes("anthropic.claude")) {
            const roleMapper = {
                system: "user",
                user: "user",
                assistant: "assistant"
            };

            const processedMessages = [];
            for (let i = 0; i < messages.length; i++) {
                const message = messages[i];
                const nextMessage = messages[i + 1];

                processedMessages.push({
                    role: roleMapper[message.role] || "user",
                    content: Array.isArray(message.content)
                        ? message.content.map(item => this.formatContentItem(item)).filter(Boolean)
                        : [{ type: "text", text: message.content }]
                });

                if (nextMessage && 
                    (message.role === "system" || message.role === "user") && 
                    (nextMessage.role === "system" || nextMessage.role === "user")) {
                    processedMessages.push({
                        role: "assistant",
                        content: [{ type: "text", text: ";" }]
                    });
                }
            }

            if (processedMessages[0]?.role === "assistant") {
                processedMessages.unshift({
                    role: "user",
                    content: [{ type: "text", text: ";" }]
                });
            }

            const requestBody = {
                anthropic_version: "bedrock-2023-05-31",
                max_tokens: modelConfig.max_tokens || 2000,
                messages: processedMessages,
                temperature: modelConfig.temperature || 0.7,
                top_p: modelConfig.top_p || 0.9,
                top_k: modelConfig.top_k || 5
            };

            if (tools.length > 0) {
                requestBody.tools = tools.map(tool => ({
                    name: tool?.function?.name || "",
                    description: tool?.function?.description || "",
                    input_schema: tool?.function?.parameters || {}
                }));
            }

            return requestBody;
        }

        // Nova models
        if (model.includes("amazon.nova")) {
            const systemMessage = messages.find(m => m.role === "system");
            const conversationMessages = messages.filter(m => m.role !== "system");

            const requestBody = {
                schemaVersion: "messages-v1",
                messages: conversationMessages.map(message => ({
                    role: message.role,
                    content: Array.isArray(message.content)
                        ? message.content.map(item => {
                            if (item.text || typeof item === "string") {
                                return { text: item.text || item };
                            }
                            if (item.image_url?.url) {
                                const url = item.image_url.url;
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
                            return null;
                        }).filter(Boolean)
                        : [{ text: message.content }]
                })),
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
                    text: Array.isArray(systemMessage.content)
                        ? systemMessage.content.map(item => item.text || "").join("\n")
                        : systemMessage.content
                }];
            }

            if (tools.length > 0) {
                requestBody.toolConfig = {
                    tools: tools.map(tool => ({
                        toolSpec: {
                            name: tool?.function?.name || "",
                            description: tool?.function?.description || "",
                            inputSchema: {
                                json: {
                                    type: "object",
                                    properties: tool?.function?.parameters?.properties || {},
                                    required: tool?.function?.parameters?.required || []
                                }
                            }
                        }
                    })),
                    toolChoice: { auto: {} }
                };
            }

            return requestBody;
        }

        // Titan models
        if (model.startsWith("amazon.titan")) {
            const inputText = messages
                .map(message => {
                    const content = Array.isArray(message.content)
                        ? message.content.map(item => item.text || "").join("\n")
                        : message.content;
                    return `${message.role}: ${content}`;
                })
                .join("\n\n");

            return {
                inputText,
                textGenerationConfig: {
                    maxTokenCount: modelConfig.max_tokens || 4096,
                    temperature: modelConfig.temperature || 0.7,
                    stopSequences: modelConfig.stop || []
                }
            };
        }

        // LLaMA models
        if (model.includes("meta.llama")) {
            let prompt = "<|begin_of_text|>";
            
            const systemMessage = messages.find(m => m.role === "system");
            if (systemMessage) {
                const content = Array.isArray(systemMessage.content)
                    ? systemMessage.content.map(item => item.text || "").join("\n")
                    : systemMessage.content;
                prompt += `<|start_header_id|>system<|end_header_id|>\n${content}<|eot_id|>`;
            }

            const conversationMessages = messages.filter(m => m.role !== "system");
            for (const message of conversationMessages) {
                const role = message.role === "assistant" ? "assistant" : "user";
                const content = Array.isArray(message.content)
                    ? message.content.map(item => item.text || "").join("\n")
                    : message.content;
                prompt += `<|start_header_id|>${role}<|end_header_id|>\n${content}<|eot_id|>`;
            }

            prompt += "<|start_header_id|>assistant<|end_header_id|>";

            return {
                prompt,
                max_gen_len: modelConfig.max_tokens || 512,
                temperature: modelConfig.temperature || 0.7,
                top_p: modelConfig.top_p || 0.9
            };
        }

        // Mistral models
        if (model.includes("mistral.mistral")) {
            const formattedMessages = messages.map(message => ({
                role: message.role === "system" ? "system" :
                      message.role === "assistant" ? "assistant" : "user",
                content: Array.isArray(message.content)
                    ? message.content.map(item => item.text || "").join("\n")
                    : message.content
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

        throw new Error(`Unsupported model: ${model}`);
    }

    /**
     * Format image generation request body
     */
    static formatImageRequestBody(prompt, config = {}) {
        const model = config.model || "stability.stable-diffusion-xl-v1";

        if (model.includes("stability.stable-diffusion")) {
            return {
                text_prompts: [
                    {
                        text: prompt,
                        weight: 1.0
                    },
                    ...(config.negative_prompt ? [{
                        text: config.negative_prompt,
                        weight: -1.0
                    }] : [])
                ],
                cfg_scale: config.cfg_scale || 7,
                steps: config.steps || 50,
                width: config.width || 1024,
                height: config.height || 1024,
                samples: config.num_images || 1,
                seed: config.seed || Math.floor(Math.random() * 4294967295)
            };
        }

        if (model.includes("amazon.titan")) {
            return {
                taskType: "TEXT_IMAGE",
                textToImageParams: {
                    text: prompt,
                    negativeText: config.negative_prompt || ""
                },
                imageGenerationConfig: {
                    cfgScale: config.cfg_scale || 7,
                    seed: config.seed || Math.floor(Math.random() * 4294967295),
                    quality: config.quality || "standard",
                    width: config.width || 1024,
                    height: config.height || 1024
                }
            };
        }

        throw new Error(`Unsupported image model: ${model}`);
    }

    /**
     * Format image upscaling request body
     */
    static formatUpscaleRequestBody(imageData, scale = 2) {
        return {
            image: imageData,
            upscaler: "esrgan",
            scale_factor: scale,
            face_enhance: true
        };
    }

    /**
     * Format image variation request body
     */
    static formatVariationRequestBody(imageData, config = {}) {
        return {
            init_image: imageData,
            cfg_scale: config.cfg_scale || 7.0,
            seed: config.seed || Math.floor(Math.random() * 4294967295),
            steps: config.steps || 50,
            strength: config.strength || 0.7
        };
    }
};
