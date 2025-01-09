<?php
/**
 * AWS Bedrock client class
 *
 * @since      1.0.0
 * @package    WP_Bedrock
 * @subpackage WP_Bedrock/includes
 */

namespace WPBEDROCK;

use Aws\Credentials\Credentials;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Exception;

class WP_Bedrock_AWS {
    private $client;
    private $max_retries = 3;
    private $retry_delay = 1; // seconds

    /**
     * Log debug message if WP_DEBUG_LOG is enabled
     *
     * @param string $message Message to log
     * @param mixed $data Optional data to include
     */
    private function log_debug($message, $data = null) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_message = '[WP Bedrock] ' . $message;
            if ($data !== null) {
                if (is_array($data) || is_object($data)) {
                    $log_message .= ' ' . json_encode($data);
                } else {
                    $log_message .= ' ' . strval($data);
                }
            }
            error_log($log_message);
        }
    }

    /**
     * Initialize AWS Bedrock client
     *
     * @param string $key AWS access key
     * @param string $secret AWS secret key
     * @param string $region AWS region
     * @param int $max_retries Maximum number of retry attempts
     * @param int $retry_delay Delay between retries in seconds
     */
    public function __construct($key, $secret, $region = 'us-west-2', $max_retries = 3, $retry_delay = 1) {
        $credentials = new Credentials($key, $secret);
        
        $this->client = new BedrockRuntimeClient([
            'version' => 'latest',
            'region'  => $region,
            'credentials' => $credentials
        ]);

        $this->max_retries = $max_retries;
        $this->retry_delay = $retry_delay;
    }

    /**
     * Execute with retry logic
     * 
     * @param callable $operation Function to execute
     * @return mixed Result of the operation
     * @throws Exception When all retries fail
     */
    private function execute_with_retry($operation) {
        $last_exception = null;
        
        for ($attempt = 1; $attempt <= $this->max_retries; $attempt++) {
            try {
                return $operation();
            } catch (Exception $e) {
                $last_exception = $e;
                $this->log_debug("Bedrock API request failed (attempt $attempt of {$this->max_retries}): " . $e->getMessage());
                
                if ($attempt < $this->max_retries) {
                    sleep($this->retry_delay * $attempt); // Exponential backoff
                }
            }
        }
        
        throw new Exception("Connection failed after {$this->max_retries} retries: " . $last_exception->getMessage());
    }

    /**
     * Format request body for different models
     * 
     * @param array $messages Array of message objects with role and content
     * @param array $config Model configuration parameters
     * @param array $tools Optional array of function tools
     * @return array Formatted request body
     */
    private function format_request_body($messages, $config, $tools = []) {
        $model_id = $config['model_id'];
        $request_body = [];

        // Nova models (including regional variants)
        if (strpos($model_id, 'amazon.nova') === 0 || strpos($model_id, 'us.amazon.nova') === 0) {
            $system_message = null;
            $conversation_messages = [];

            foreach ($messages as $message) {
                if ($message['role'] === 'system') {
                    $system_message = $message;
                } else {
                    $conversation_messages[] = $message;
                }
            }

            // Format Nova request body
            $request_body = [
                'schemaVersion' => 'messages-v1',
                'messages' => array_map(function($message) {
                    $content = [];
                    
                    if (is_string($message['content'])) {
                        $content[] = ['text' => $message['content']];
                    } else if (is_array($message['content'])) {
                        foreach ($message['content'] as $item) {
                            if (isset($item['text']) || is_string($item)) {
                                $content[] = ['text' => is_string($item) ? $item : $item['text']];
                            } else if (isset($item['image_url']) && isset($item['image_url']['url']) && $item['image_url']['url'] !== 'null') {
                                $url = $item['image_url']['url'];
                                $colonIndex = strpos($url, ':');
                                $semicolonIndex = strpos($url, ';');
                                $comma = strpos($url, ',');
                                
                                if ($colonIndex !== false && $semicolonIndex !== false && $comma !== false) {
                                    $mimeType = substr($url, $colonIndex + 1, $semicolonIndex - $colonIndex - 1);
                                    $format = explode('/', $mimeType)[1];
                                    $data = substr($url, $comma + 1);
                                    
                                    $content[] = [
                                        'image' => [
                                            'format' => $format,
                                            'source' => [
                                                'bytes' => $data
                                            ]
                                        ]
                                    ];
                                }
                            }
                        }
                    }
                    
                    return [
                        'role' => $message['role'],
                        'content' => $content
                    ];
                }, $conversation_messages),
                'inferenceConfig' => [
                    'temperature' => $config['temperature'] ?? 0.7,
                    'top_p' => $config['top_p'] ?? 0.9,
                    'top_k' => $config['top_k'] ?? 50,
                    'max_new_tokens' => $config['max_tokens'] ?? 1000,
                    'stopSequences' => $config['stop'] ?? []
                ]
            ];

            // Add system message if present
            if ($system_message) {
                $system_content = is_array($system_message['content']) 
                    ? implode("\n", array_map(function($c) { 
                        return isset($c['text']) ? $c['text'] : ''; 
                    }, $system_message['content'])) 
                    : $system_message['content'];
                    
                $request_body['system'] = [['text' => $system_content]];
            }

            if (!empty($tools)) {
                $request_body['toolConfig'] = [
                    'tools' => array_map(function($tool) {
                        return [
                            'toolSpec' => [
                                'name' => $tool['function']['name'] ?? '',
                                'description' => $tool['function']['description'] ?? '',
                                'inputSchema' => [
                                    'json' => [
                                        'type' => 'object',
                                        'properties' => $tool['function']['parameters']['properties'] ?? [],
                                        'required' => $tool['function']['parameters']['required'] ?? []
                                    ]
                                ]
                            ]
                        ];
                    }, $tools),
                    'toolChoice' => ['auto' => []]
                ];
            }
        }
        // Claude models (including Claude 3 and 3.5)
        else if (strpos($model_id, 'anthropic.claude') === 0 || strpos($model_id, 'us.anthropic.claude') === 0) {
            // Handle Claude models
            $processed_messages = [];
            
            foreach ($messages as $message) {
                if (empty($message['content'])) continue;
                if (is_string($message['content']) && !trim($message['content'])) continue;
                
                // Convert system messages to user messages
                if ($message['role'] === 'system') {
                    $message['role'] = 'user';
                }
                
                // Format content
                if (is_array($message['content'])) {
                    $text_parts = [];
                    foreach ($message['content'] as $item) {
                        if (isset($item['text']) || is_string($item)) {
                            $text_parts[] = is_string($item) ? $item : $item['text'];
                        }
                    }
                    $message['content'] = implode("\n", array_filter($text_parts));
                }
                
                $processed_messages[] = $message;
            }
            
            $request_body = [
                'anthropic_version' => 'bedrock-2023-05-31',
                'max_tokens' => $config['max_tokens'] ?? 2000,
                'temperature' => $config['temperature'] ?? 0.7,
                'top_p' => $config['top_p'] ?? 0.9,
                'top_k' => $config['top_k'] ?? 5,
                'messages' => $processed_messages
            ];
            
            // Add tools if available
            if (!empty($tools)) {
                $request_body['tools'] = array_map(function($tool) {
                    return [
                        'name' => $tool['function']['name'] ?? '',
                        'description' => $tool['function']['description'] ?? '',
                        'input_schema' => $tool['function']['parameters'] ?? []
                    ];
                }, $tools);
            }
        }
        // Llama 3 models (including all versions and regional variants)
        else if (strpos($model_id, 'meta.llama3') === 0 || strpos($model_id, 'us.meta.llama3') === 0) {
            // Format LLaMA prompt with proper tokens and role handling
            $prompt = '<|begin_of_text|>';
            
            // Add system message if present
            $system_message = array_filter($messages, function($m) { return $m['role'] === 'system'; })[0] ?? null;
            if ($system_message) {
                $system_content = is_array($system_message['content']) 
                    ? implode("\n", array_map(function($c) { 
                        return isset($c['text']) ? $c['text'] : ''; 
                    }, $system_message['content'])) 
                    : $system_message['content'];
                $prompt .= "<|start_header_id|>system<|end_header_id|>\n{$system_content}<|eot_id|>";
            }

            // Add conversation messages
            $conversation_messages = array_filter($messages, function($m) { return $m['role'] !== 'system'; });
            foreach ($conversation_messages as $message) {
                $role = $message['role'] === 'assistant' ? 'assistant' : 'user';
                $content = is_array($message['content']) 
                    ? implode("\n", array_map(function($c) { 
                        return isset($c['text']) ? $c['text'] : ''; 
                    }, $message['content'])) 
                    : $message['content'];
                    
                if (!empty(trim($content))) {
                    $prompt .= "<|start_header_id|>{$role}<|end_header_id|>\n{$content}<|eot_id|>";
                }
            }

            // Add final assistant header for completion
            $prompt .= '<|start_header_id|>assistant<|end_header_id|>';

            $request_body = [
                'prompt' => $prompt,
                'max_gen_len' => $config['max_tokens'] ?? 512,
                'temperature' => $config['temperature'] ?? 0.7,
                'top_p' => $config['top_p'] ?? 0.9
            ];
        }
        // Mistral models (including all versions)
        else if (strpos($model_id, 'mistral.mistral-large') === 0) {
            $mistral_mapper = [
                'system' => 'user',
                'user' => 'user',
                'assistant' => 'assistant'
            ];

            // Format Mistral request with proper role mapping and content handling
            $request_body = [
                'messages' => array_map(function($message) use ($mistral_mapper) {
                    $content = '';
                    
                    if (is_string($message['content'])) {
                        $content = $message['content'];
                    } else if (is_array($message['content'])) {
                        $content = implode("\n", array_map(function($c) {
                            if (is_string($c)) return $c;
                            if (isset($c['text'])) return $c['text'];
                            return '';
                        }, array_filter($message['content'], function($c) {
                            return !isset($c['image_url']); // Mistral doesn't support images
                        })));
                    }
                    
                    return [
                        'role' => $mistral_mapper[$message['role']] ?? 'user',
                        'content' => trim($content)
                    ];
                }, array_filter($messages, function($message) {
                    return !empty($message['content']);
                })),
                'max_tokens' => $config['max_tokens'] ?? 4096,
                'temperature' => $config['temperature'] ?? 0.7,
                'top_p' => $config['top_p'] ?? 0.9
            ];

            if (!empty($tools)) {
                $request_body['tool_choice'] = 'auto';
                $request_body['tools'] = array_map(function($tool) {
                    return [
                        'type' => 'function',
                        'function' => $tool['function']
                    ];
                }, $tools);
            }
        }

        return $request_body;
    }

    /**
     * Invoke Bedrock model
     *
     * Supports two parameter formats:
     * 1. New format: ($messages_array, $config_array, $tools_array, $stream, $callback)
     * 2. Legacy format: ($message_text, $model_id, $temperature, $max_tokens, $stream, $callback, $tools)
     *
     * @param array|string $messages_or_text Either an array of message objects or a single message string
     * @param array|string|null $model_id Either a config array or model ID string
     * @param float|null $temperature Temperature parameter (0-1)
     * @param int|null $max_tokens Maximum tokens to generate
     * @param bool $stream Whether to stream the response
     * @param callable|null $callback Callback function for streaming responses
     * @param array $tools Optional array of function tools
     * @return string|null Model response (null if streaming)
     */
    public function invoke_model($messages_or_text, $model_id = null, $temperature = null, $max_tokens = null, $stream = false, $callback = null, $tools = []) {
            // Convert parameters to standardized format
        if (is_string($messages_or_text)) {
            // Legacy format: convert single message to messages array
            $messages = [
                [
                    'role' => 'user',
                    'content' => $messages_or_text
                ]
            ];
            
            // Convert individual parameters to config object
            $config = [
                'model_id' => $model_id ?: 'us.anthropic.claude-3-haiku-20240307-v1',
                'temperature' => $temperature ?: 0.7,
                'max_tokens' => $max_tokens ?: 2000,
                'top_p' => 0.9,
                'top_k' => 5
            ];
        } else if (is_array($messages_or_text) && isset($messages_or_text[0]['role'])) {
            // New format: messages array already provided
            $messages = array_map(function($message) {
                // Convert system messages to user messages
                if ($message['role'] === 'system') {
                    $message['role'] = 'user';
                }
                
                // Ensure content is properly formatted
                if (is_array($message['content'])) {
                    $text_parts = [];
                    foreach ($message['content'] as $item) {
                        if (isset($item['text']) || is_string($item)) {
                            $text_parts[] = is_string($item) ? $item : $item['text'];
                        }
                    }
                    $message['content'] = implode("\n", array_filter($text_parts));
                }
                
                return $message;
            }, $messages_or_text);
            
            if (is_array($model_id)) {
                // Config object provided
                $config = array_merge([
                    'model_id' => 'us.anthropic.claude-3-haiku-20240307-v1',
                    'temperature' => 0.7,
                    'max_tokens' => 2000,
                    'top_p' => 0.9,
                    'top_k' => 5
                ], $model_id);
            } else {
                // Individual parameters provided
                $config = [
                    'model_id' => $model_id ?: 'anthropic.claude-3-haiku-20240307-v1',
                    'temperature' => $temperature ?: 0.7,
                    'max_tokens' => $max_tokens ?: 2000,
                    'top_p' => 0.9,
                    'top_k' => 5
                ];
            }
        } else {
            throw new Exception('Invalid message format. Expected string or array of messages.');
        }

            $this->log_debug('Formatting request body with:', [
                'model_id' => $config['model_id'],
                'temperature' => $config['temperature'],
                'max_tokens' => $config['max_tokens'],
                'messages_count' => count($messages)
            ]);
            
            foreach ($messages as $index => $message) {
                $this->log_debug("Message $index:", [
                    'role' => $message['role'],
                    'content_type' => gettype($message['content']),
                    'content' => $message['content']
                ]);
            }

            $request_body = $this->format_request_body($messages, $config, $tools);
            $this->log_debug('Formatted request body:', $request_body);

            try {
            $params = [
                'body' => json_encode($request_body),
                'contentType' => 'application/json',
                'modelId' => $config['model_id']
            ];
            
            $this->log_debug('AWS Bedrock request params:', $params);

            if ($stream) {
                $response = $this->execute_with_retry(function() use ($params) {
                    return $this->client->invokeModelWithResponseStream($params);
                });
                if (!isset($response['body'])) {
                    throw new Exception('Invalid streaming response: missing body');
                }
                
                $eventStream = $response['body'];
                $this->log_debug('Stream started', [
                    'response_type' => gettype($response['body']),
                    'model_id' => $config['model_id'],
                    'streaming_mode' => $callback ? 'with callback' : 'accumulate only'
                ]);
                
                if (!is_object($eventStream) || !method_exists($eventStream, 'current')) {
                    throw new Exception('Invalid event stream: expected iterator');
                }
                
                $fullResponse = '';
                foreach ($eventStream as $event) {
                    try {
                        if (isset($event['chunk'])) {
                            $this->log_debug('Processing chunk of type:', gettype($event['chunk']));
                            
                            try {
                                $chunkBytes = is_object($event['chunk']) && method_exists($event['chunk'], 'getContents') 
                                    ? $event['chunk']->getContents()
                                    : (is_array($event['chunk']) ? json_encode($event['chunk']) : strval($event['chunk']));
                                    
                                $this->log_debug('Successfully processed chunk bytes');
                            } catch (Exception $e) {
                                $this->log_debug('Error processing chunk:', $e->getMessage());
                                continue;
                            }
                            $this->log_debug('Stream chunk received');
                            
                            $chunk = json_decode($chunkBytes, true);
                            if ($chunk === null && json_last_error() !== JSON_ERROR_NONE) {
                                $this->log_debug('Failed to decode chunk:', json_last_error_msg());
                                continue;
                            }
                            if ($chunk !== null) {
                                $this->log_debug('Decoded chunk:', $chunk);
                                
                                // Handle Nova model streaming response
                                if (strpos($config['model_id'], 'us.amazon.nova') === 0) {
                                    if (isset($chunk['contentBlockDelta']) && isset($chunk['contentBlockDelta']['delta']['text'])) {
                                        $text = $chunk['contentBlockDelta']['delta']['text'];
                                        $this->log_debug('Nova text received:', $text);
                                        $fullResponse .= $text;
                                        if ($callback) {
                                            call_user_func($callback, ['text' => $text]);
                                        }
                                    }
                                } else if (isset($chunk['type'])) {
                                    // Claude 3 and other models streaming response format
                                    switch ($chunk['type']) {
                                        case 'message_start':
                                            $this->log_debug('Message start received');
                                            break;
                                            
                                        case 'content_block_start':
                                            $this->log_debug('Content block start received');
                                            break;
                                            
                                        case 'content_block_delta':
                                            if (isset($chunk['delta']['text'])) {
                                                $text = $chunk['delta']['text'];
                                                $this->log_debug('Delta text received:', $text);
                                                $fullResponse .= $text;
                                                if ($callback) {
                                                    call_user_func($callback, ['text' => $text]);
                                                }
                                            }
                                            break;
                                            
                                        case 'content_block_stop':
                                            $this->log_debug('Content block stop received');
                                            break;
                                            
                                        case 'message_stop':
                                            $this->log_debug('Message stop received');
                                            break;
                                    }
                                }
                            } else {
                                $this->log_debug('Failed to decode chunk');
                            }
                        }
                    } catch (Exception $e) {
                        $this->log_debug('Error processing stream event:', $e->getMessage());
                    }
                }
                return $fullResponse;
            } else {
                $response = $this->execute_with_retry(function() use ($params) {
                    return $this->client->invokeModel($params);
                });
                
                try {
                    $responseData = json_encode($response);
                    $this->log_debug('Raw response:', $responseData ?: 'Response not JSON encodable');
                    
                    $responseContent = $response['body']->getContents();
                    $this->log_debug('Response content:', $responseContent);
                    
                    $result = json_decode($responseContent, true);
                    if ($result !== null) {
                        $this->log_debug('Decoded result:', $result);
                    } else {
                        $this->log_debug('Failed to decode response content');
                        throw new Exception('Failed to decode response content');
                    }
                    
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->log_debug('JSON decode error:', json_last_error_msg());
                        throw new Exception('Failed to decode response: ' . json_last_error_msg());
                    }
                    
                    $parsedResponse = $this->parse_response($result, $config['model_id']);
                    $this->log_debug('Parsed response:', $parsedResponse);
                    
                    return $parsedResponse;
                } catch (Exception $e) {
                    $this->log_debug('Error processing response:', $e->getMessage());
                    throw $e;
                }
            }
        } catch (Exception $e) {
            throw new Exception('Error invoking Bedrock model: ' . $e->getMessage());
        }
    }

    /**
     * Generate image using Bedrock model
     * 
     * @param string $prompt Text prompt for image generation
     * @param array $config Configuration parameters
     * @return string Base64 encoded image data
     */
    /**
     * Generate one or more images
     * 
     * @param string $prompt Text prompt for image generation
     * @param array $config Configuration parameters
     * @param callable|null $progress_callback Optional callback for progress updates
     * @return array Array of generated images with metadata
     */
    public function generate_image($prompt, $config = [], $progress_callback = null) {
        $default_config = [
            'model_id' => 'stability.stable-diffusion-xl-v1',
            'cfg_scale' => 7,
            'seed' => rand(0, 4294967295),
            'steps' => 50,
            'width' => 1024,
            'height' => 1024,
            'style_preset' => 'photographic',
            'negative_prompt' => '',
            'num_images' => 1,
            'quality' => 'standard'
        ];

        $config = array_merge($default_config, $config);
        $model_type = 'bedrock-sd';
        if (strpos($config['model_id'], 'amazon.titan') === 0) {
            $model_type = 'bedrock-titan';
        } else if (strpos($config['model_id'], 'amazon.nova') === 0) {
            $model_type = 'bedrock-nova';
        }

        if ($model_type === 'bedrock-sd') {
            $request_body = [
                'text_prompts' => [
                    [
                        'text' => $prompt,
                        'weight' => 1.0
                    ]
                ],
                'cfg_scale' => $config['cfg_scale'],
                'steps' => $config['steps'],
                'width' => $config['width'],
                'height' => $config['height'],
                'style_preset' => $config['style_preset'],
                'samples' => $config['num_images']
            ];

            // Add negative prompt if provided
            if (!empty($config['negative_prompt'])) {
                $request_body['text_prompts'][] = [
                    'text' => $config['negative_prompt'],
                    'weight' => -1.0
                ];
            }

            // Generate seeds for each image
            $seeds = [];
            for ($i = 0; $i < $config['num_images']; $i++) {
                $seeds[] = $config['seed'] !== -1 ? $config['seed'] + $i : rand(0, 4294967295);
            }
            $request_body['seeds'] = $seeds;
        } else if ($model_type === 'bedrock-titan') {
            $request_body = [
                'taskType' => 'TEXT_IMAGE',
                'textToImageParams' => [
                    'text' => $prompt,
                    'negativeText' => $config['negative_prompt']
                ],
                'imageGenerationConfig' => [
                    'cfgScale' => $config['cfg_scale'],
                    'seed' => $config['seed'],
                    'quality' => $config['quality'],
                    'width' => $config['width'],
                    'height' => $config['height']
                ]
            ];
        } else if ($model_type === 'bedrock-nova') {
            // Nova Canvas format
            $request_body = [
                'taskType' => 'TEXT_IMAGE',
                'textToImageParams' => [
                    'text' => $prompt,
                    'negativeText' => $config['negative_prompt'],
                ],
                'imageGenerationConfig' => [
                    'numberOfImages' => $config['num_images'],
                    'quality' => $config['quality'],
                    'height' => $config['height'],
                    'width' => $config['width'],
                    'cfgScale' => $config['cfg_scale'],
                    'seed' => $config['seed']
                ]
            ];
        }

        try {
            $params = [
                'body' => json_encode($request_body),
                'contentType' => 'application/json',
                'modelId' => $config['model_id']
            ];

            $this->log_debug('AWS Bedrock image generation request params:', $params);

            $response = $this->execute_with_retry(function() use ($params) {
                return $this->client->invokeModel($params);
            });

            $result = json_decode($response['body']->getContents(), true);

            if (isset($result['artifacts']) && !empty($result['artifacts'])) {
                return array_map(function($artifact) {
                    return [
                        'image' => $artifact['base64'],
                        'seed' => $artifact['seed'] ?? -1
                    ];
                }, $result['artifacts']);
            }

            throw new Exception('No images generated in response');
        } catch (Exception $e) {
            throw new Exception('Error generating image: ' . $e->getMessage());
        }
    }

    /**
     * Upscale an image using Stable Diffusion
     * 
     * @param string $image_data Base64 encoded image data
     * @param int $scale Upscale factor (2 or 4)
     * @return string Base64 encoded upscaled image
     */
    public function upscale_image($image_data, $scale = 2) {
        try {
            $request_body = [
                'image' => $image_data,
                'upscaler' => 'esrgan',
                'scale_factor' => $scale,
                'face_enhance' => true
            ];

            $params = [
                'body' => json_encode($request_body),
                'contentType' => 'application/json',
                'modelId' => 'stability.stable-diffusion-xl-upscaler'
            ];

            $response = $this->execute_with_retry(function() use ($params) {
                return $this->client->invokeModel($params);
            });

            $result = json_decode($response['body']->getContents(), true);

            if (isset($result['artifacts']) && !empty($result['artifacts'])) {
                return $result['artifacts'][0]['base64'];
            }

            throw new Exception('No upscaled image in response');
        } catch (Exception $e) {
            throw new Exception('Error upscaling image: ' . $e->getMessage());
        }
    }

    /**
     * Create a variation of an existing image
     * 
     * @param string $image_data Base64 encoded image data
     * @param array $config Configuration parameters
     * @return array Generated image data with metadata
     */
    public function create_image_variation($image_data, $config = []) {
        $default_config = [
            'strength' => 0.7,
            'seed' => rand(0, 4294967295),
            'cfg_scale' => 7.0,
            'steps' => 50
        ];

        $config = array_merge($default_config, $config);

        try {
            $request_body = [
                'init_image' => $image_data,
                'cfg_scale' => $config['cfg_scale'],
                'seed' => $config['seed'],
                'steps' => $config['steps'],
                'strength' => $config['strength']
            ];

            $params = [
                'body' => json_encode($request_body),
                'contentType' => 'application/json',
                'modelId' => 'stability.stable-diffusion-xl-v1'
            ];

            $response = $this->execute_with_retry(function() use ($params) {
                return $this->client->invokeModel($params);
            });

            $result = json_decode($response['body']->getContents(), true);

            if (isset($result['artifacts']) && !empty($result['artifacts'])) {
                return [
                    'image' => $result['artifacts'][0]['base64'],
                    'seed' => $result['artifacts'][0]['seed'] ?? $config['seed']
                ];
            }

            throw new Exception('No variation image in response');
        } catch (Exception $e) {
            throw new Exception('Error creating image variation: ' . $e->getMessage());
        }
    }

    /**
     * Parse model response based on model type
     * 
     * @param array $result Raw response from model
     * @param string $model_id Model identifier
     * @return string|array Parsed response
     */
    private function parse_response($result, $model_id) {
        $this->log_debug('Parsing response for model:', $model_id);
        $this->log_debug('Response to parse:', $result);
        
        // Claude models (including Claude 3 and 3.5)
        if (strpos($model_id, 'anthropic.claude') === 0 || strpos($model_id, 'us.anthropic.claude') === 0) {
            $this->log_debug('Parsing Claude response');
            if (isset($result['content']) && is_array($result['content'])) {
                $response = array_reduce($result['content'], function($text, $content) {
                    return $text . ($content['text'] ?? '');
                }, '');
                $this->log_debug('Parsed Claude response:', $response);
                return $response;
            }
            $this->log_debug('Invalid Claude response format');
        }
        // Nova models (including regional variants)
        else if (strpos($model_id, 'amazon.nova') === 0 || strpos($model_id, 'us.amazon.nova') === 0) {
            if (isset($result['content']) && is_array($result['content'])) {
                return array_reduce($result['content'], function($text, $content) {
                    return $text . ($content['text'] ?? '');
                }, '');
            }
        }
        // Llama 3 models (including all versions and regional variants)
        else if (strpos($model_id, 'meta.llama3') === 0 || strpos($model_id, 'us.meta.llama3') === 0) {
            return $result['generation'] ?? '';
        }
        // Mistral models (including all versions)
        else if (strpos($model_id, 'mistral.mistral-large') === 0) {
            if (isset($result['choices'][0]['message'])) {
                $message = $result['choices'][0]['message'];
                
                // Check for function call
                if (isset($message['tool_calls'])) {
                    return [
                        'content' => $message['content'] ?? '',
                        'tool_calls' => $message['tool_calls']
                    ];
                }
                
                return $message['content'] ?? '';
            }
        }
        
        throw new Exception('Unsupported model response format');
    }
}
