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
                error_log("Bedrock API request failed (attempt $attempt of {$this->max_retries}): " . $e->getMessage());
                
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

        // Nova models
        if (strpos($model_id, 'amazon.nova') === 0 || strpos($model_id, 'us.amazon.nova') === 0) {
            $system_message = null;
            $conversation_messages = [];

            foreach ($messages as $message) {
                if ($message['role'] === 'system') {
                    $system_message = $message;
                } else {
                    $conversation_messages[] = [
                        'role' => $message['role'],
                        'content' => is_array($message['content']) ? $message['content'] : [['text' => $message['content']]]
                    ];
                }
            }

            $request_body = [
                'schemaVersion' => 'messages-v1',
                'messages' => $conversation_messages,
                'inferenceConfig' => [
                    'temperature' => $config['temperature'] ?? 0.7,
                    'top_p' => $config['top_p'] ?? 0.9,
                    'top_k' => $config['top_k'] ?? 50,
                    'max_new_tokens' => $config['max_tokens'] ?? 1000,
                    'stopSequences' => $config['stop'] ?? []
                ]
            ];

            if ($system_message) {
                $request_body['system'] = [
                    ['text' => $system_message['content']]
                ];
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
        // Claude models
        else if (strpos($model_id, 'anthropic.claude') === 0 || strpos($model_id, 'us.anthropic.claude') === 0) {
            $formatted_messages = [];
            $keys = ['system', 'user'];

            foreach ($messages as $i => $message) {
                if ($i > 0 && in_array($messages[$i-1]['role'], $keys) && in_array($message['role'], $keys)) {
                    $formatted_messages[] = [
                        'role' => 'assistant',
                        'content' => ';'
                    ];
                }

                $content = $message['content'];
                if (!is_array($content)) {
                    $content = [['type' => 'text', 'text' => $content]];
                }

                $formatted_messages[] = [
                    'role' => $message['role'],
                    'content' => $content
                ];
            }

            if ($formatted_messages[0]['role'] === 'assistant') {
                array_unshift($formatted_messages, [
                    'role' => 'user',
                    'content' => ';'
                ]);
            }

            $request_body = [
                'anthropic_version' => 'bedrock-2023-05-31',
                'messages' => $formatted_messages,
                'max_tokens' => $config['max_tokens'] ?? 2000,
                'temperature' => $config['temperature'] ?? 0.7,
                'top_p' => $config['top_p'] ?? 0.9,
                'top_k' => $config['top_k'] ?? 5
            ];

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
        // Llama models
        else if (strpos($model_id, 'meta.llama') === 0 || strpos($model_id, 'us.meta.llama') === 0) {
            $prompt = '<|begin_of_text|>';
            
            foreach ($messages as $message) {
                if ($message['role'] === 'system') {
                    $prompt .= "<|start_header_id|>system<|end_header_id|>\n{$message['content']}<|eot_id|>";
                } else {
                    $role = $message['role'] === 'assistant' ? 'assistant' : 'user';
                    $content = is_array($message['content']) ? implode("\n", array_map(function($c) {
                        return $c['text'] ?? '';
                    }, $message['content'])) : $message['content'];
                    $prompt .= "<|start_header_id|>{$role}<|end_header_id|>\n{$content}<|eot_id|>";
                }
            }

            $prompt .= '<|start_header_id|>assistant<|end_header_id|>';

            $request_body = [
                'prompt' => $prompt,
                'max_gen_len' => $config['max_tokens'] ?? 512,
                'temperature' => $config['temperature'] ?? 0.7,
                'top_p' => $config['top_p'] ?? 0.9
            ];
        }
        // Mistral models
        else if (strpos($model_id, 'mistral.') === 0) {
            $request_body = [
                'messages' => array_map(function($message) {
                    return [
                        'role' => $message['role'] === 'system' ? 'user' : $message['role'],
                        'content' => is_array($message['content']) ? implode("\n", array_map(function($c) {
                            return $c['text'] ?? '';
                        }, $message['content'])) : $message['content']
                    ];
                }, $messages),
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
        // Handle both new and old parameter formats
        if (is_string($messages_or_text)) {
            // Old format: individual parameters
            $messages = [
                [
                    'role' => 'user',
                    'content' => $messages_or_text
                ]
            ];
            $config = [
                'model_id' => $model_id ?: 'anthropic.claude-3-haiku-20240307-v1',
                'temperature' => $temperature ?: 0.7,
                'max_tokens' => $max_tokens ?: 2000,
                'top_p' => 0.9,
                'top_k' => 5
            ];
        } else {
            // New format: messages array and config object
            $messages = $messages_or_text;
            $config = is_string($model_id) ? [
                'model_id' => $model_id,
                'temperature' => $temperature ?: 0.7,
                'max_tokens' => $max_tokens ?: 2000,
                'top_p' => 0.9,
                'top_k' => 5
            ] : ($model_id ?: []);

            // Set default config values
            $config = array_merge([
                'model_id' => 'anthropic.claude-3-haiku-20240307-v1',
                'temperature' => 0.7,
                'max_tokens' => 2000,
                'top_p' => 0.9,
                'top_k' => 5
            ], $config);
        }

        $request_body = $this->format_request_body($messages, $config, $tools);

        try {
            $params = [
                'body' => json_encode($request_body),
                'contentType' => 'application/json',
                'modelId' => $config['model_id']
            ];
            
            error_log('AWS Bedrock request params: ' . json_encode($params));

            if ($stream) {
                $response = $this->execute_with_retry(function() use ($params) {
                    return $this->client->invokeModelWithResponseStream($params);
                });
                $eventStream = $response['body'];
                
                $fullResponse = '';
                foreach ($eventStream as $event) {
                    error_log('Stream event received: ' . json_encode($event));
                    
                    if (isset($event['chunk']['bytes'])) {
                        $chunk = json_decode($event['chunk']['bytes'], true);
                        error_log('Decoded chunk: ' . json_encode($chunk));
                        
                        // Handle Nova model streaming response
                        if (strpos($config['model_id'], 'us.amazon.nova') === 0) {
                            if (isset($chunk['contentBlockDelta']) && isset($chunk['contentBlockDelta']['delta']['text'])) {
                                $text = $chunk['contentBlockDelta']['delta']['text'];
                                error_log('Nova text received: ' . $text);
                                $fullResponse .= $text;
                                if ($callback) {
                                    call_user_func($callback, $text);
                                }
                            }
                        } else if (isset($chunk['type'])) {
                            // Claude 3 和其他模型的流式响应格式
                            switch ($chunk['type']) {
                                case 'message_start':
                                    error_log('Message start received');
                                    break;
                                    
                                case 'content_block_start':
                                    error_log('Content block start received');
                                    break;
                                    
                                case 'content_block_delta':
                                    if (isset($chunk['delta']['text'])) {
                                        $text = $chunk['delta']['text'];
                                        error_log('Delta text received: ' . $text);
                                        $fullResponse .= $text;
                                        if ($callback) {
                                            call_user_func($callback, $text);
                                        }
                                    }
                                    break;
                                    
                                case 'content_block_stop':
                                    error_log('Content block stop received');
                                    break;
                                    
                                case 'message_stop':
                                    error_log('Message stop received');
                                    break;
                            }
                        }
                    }
                }
                return $fullResponse;
            } else {
                $response = $this->execute_with_retry(function() use ($params) {
                    return $this->client->invokeModel($params);
                });
                $result = json_decode($response['body']->getContents(), true);
                
                return $this->parse_response($result, $config['model_id']);
            }
        } catch (Exception $e) {
            throw new Exception('Error invoking Bedrock model: ' . $e->getMessage());
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
        // Claude models
        if (strpos($model_id, 'anthropic.claude') === 0 || strpos($model_id, 'us.anthropic.claude') === 0) {
            if (isset($result['content']) && is_array($result['content'])) {
                return array_reduce($result['content'], function($text, $content) {
                    return $text . ($content['text'] ?? '');
                }, '');
            }
        }
        // Nova models
        else if (strpos($model_id, 'amazon.nova') === 0 || strpos($model_id, 'us.amazon.nova') === 0) {
            if (isset($result['content']) && is_array($result['content'])) {
                return array_reduce($result['content'], function($text, $content) {
                    return $text . ($content['text'] ?? '');
                }, '');
            }
        }
        // Llama models
        else if (strpos($model_id, 'meta.llama') === 0 || strpos($model_id, 'us.meta.llama') === 0) {
            return $result['generation'] ?? '';
        }
        // Mistral models
        else if (strpos($model_id, 'mistral.') === 0) {
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
