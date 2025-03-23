<?php
namespace AICHAT_AMAZON_BEDROCK;

use Exception;

class WP_Bedrock_AWS {
    private $access_key;
    private $secret_key;
    private $region;
    private $max_retries = 3;
    private $retry_delay = 2;
    private $jitter = 0.1;
    private $timeout = 30;

    public function __construct($key, $secret, $region = null, $max_retries = 3, $retry_delay = 1) {
        $this->access_key = $key;
        $this->secret_key = $secret;
        
        // Use provided region or get from settings
        $this->region = $region ?: get_option('wpbedrock_aws_region');
        if (empty($this->region)) {
            throw new Exception('AWS region not configured. Please set the region in plugin settings.');
        }
        
        $this->max_retries = $max_retries;
        $this->retry_delay = $retry_delay;
        
        // Log initialization
        $this->log_debug('Initializing AWS client with region: ' . $this->region);
    }

    private function sanitize_log_data($data) {
        if (is_string($data)) {
            // Mask potential sensitive data in strings
            return preg_replace([
                '/("accessKey"|"secretKey"|"key"|"secret")\s*:\s*"[^"]*"/',
                '/("authorization")\s*:\s*"[^"]*"/',
                '/("password"|"token"|"apiKey")\s*:\s*"[^"]*"/'
            ], '$1:"[REDACTED]"', $data);
        }
        
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                // Skip sensitive keys entirely
                if (in_array(strtolower($key), ['authorization', 'password', 'token', 'apikey', 'secret', 'accesskey', 'secretkey'])) {
                    continue;
                }
                // Recursively sanitize nested data
                $sanitized[$key] = $this->sanitize_log_data($value);
            }
            return $sanitized;
        }
        
        return $data;
    }

    private function log_debug($message, $data = null) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_message = '[AI Chat for Amazon Bedrock] ' . $message;
            if ($data !== null) {
                // Sanitize any sensitive data before logging
                $sanitized_data = $this->sanitize_log_data($data);
                $log_message .= ' ' . (is_string($sanitized_data) ? $sanitized_data : json_encode($sanitized_data));
            }
            error_log($log_message);
        }
    }

    private function get_canonical_uri($path) {
        if (empty($path) || $path === '/') return '/';

        $segments = explode('/', trim($path, '/'));
        $canonical_segments = array_map(function($segment) {
            if (empty($segment)) return '';
            if ($segment === 'invoke-with-response-stream') return $segment;

            if (strpos($segment, 'model/') !== false) {
                $parts = preg_split('/(model\/)/', $segment, -1, PREG_SPLIT_DELIM_CAPTURE);
                return implode('', array_map(function($part) {
                    if ($part === 'model/') return $part;
                    return implode('', array_map(function($subpart) {
                        return preg_match('/[.:]/', $subpart) ? $subpart : rawurlencode($subpart);
                    }, preg_split('/([.:])/u', $part, -1, PREG_SPLIT_DELIM_CAPTURE)));
                }, $parts));
            }

            return rawurlencode($segment);
        }, $segments);

        return '/' . implode('/', $canonical_segments);
    }

    private function get_bedrock_endpoint($model_id, $stream = false) {
        // Use WordPress filter to allow customization of endpoint
        $endpoint = apply_filters(
            'wpbedrock_aws_endpoint',
            // Use AWS SDK standard endpoint format - include in plugin, not remote
            "bedrock-runtime.{$this->region}.amazonaws.com",
            $this->region
        );
        
        // Ensure we're using HTTPS
        if (strpos($endpoint, 'http') !== 0) {
            $endpoint = 'https://' . $endpoint;
        }
        
        $path = "/model/{$model_id}";
        $operation = $stream ? '/invoke-with-response-stream' : '/invoke';
        
        // Log endpoint construction
        $full_url = $endpoint . $path . $operation;
        $this->log_debug('Constructed endpoint:', $full_url);
        
        return $full_url;
    }

    private function create_hmac($key, $string) {
        return hash_hmac('sha256', $string, $key, true);
    }

    private function get_signing_key($secret_key, $date_stamp, $region, $service) {
        $k_date = $this->create_hmac('AWS4' . $secret_key, $date_stamp);
        $k_region = $this->create_hmac($k_date, $region);
        $k_service = $this->create_hmac($k_region, $service);
        return $this->create_hmac($k_service, 'aws4_request');
    }

    private function sign_request($method, $url, $body, $is_streaming = false) {
        $parsed_url = wp_parse_url($url);
        $now = gmdate('Ymd\THis\Z');
        $date_stamp = substr($now, 0, 8);
        
        // Ensure body is properly formatted JSON
        $json_body = is_string($body) ? $body : json_encode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid request body format');
        }

        // Calculate payload hash
        $payload_hash = hash('sha256', $json_body);
        
        // Required headers in specific order
        $headers = [
            'host' => $parsed_url['host'],
            'x-amz-date' => $now,
            'x-amz-content-sha256' => $payload_hash
        ];
        
        // Add content-type and accept headers
        $headers['content-type'] = 'application/json';
        $headers['accept'] = $is_streaming ? 'application/vnd.amazon.eventstream' : 'application/json';
        
        if ($is_streaming) {
            $headers['x-amzn-bedrock-accept'] = '*/*';
        }

        // Create canonical request (headers must be in alphabetical order)
        ksort($headers);
        $canonical_headers = '';
        $signed_headers = [];
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            $signed_headers[] = strtolower($key);
        }
        sort($signed_headers);
        $signed_headers_str = implode(';', $signed_headers);

        $canonical_uri = $this->get_canonical_uri($parsed_url['path']);
        $canonical_query = isset($parsed_url['query']) ? $parsed_url['query'] : '';

        $canonical_request = implode("\n", [
            $method,
            $canonical_uri,
            $canonical_query,
            $canonical_headers,
            $signed_headers_str,
            $payload_hash
        ]);

        // Create string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = $date_stamp . '/' . $this->region . '/bedrock/aws4_request';
        
        $string_to_sign = implode("\n", [
            $algorithm,
            $now,
            $credential_scope,
            hash('sha256', $canonical_request)
        ]);

        // Calculate signature using signing key
        $signing_key = $this->get_signing_key($this->secret_key, $date_stamp, $this->region, 'bedrock');
        $signature = bin2hex($this->create_hmac($signing_key, $string_to_sign));

        // Add authorization header
        $headers['authorization'] = $algorithm . 
            ' Credential=' . $this->access_key . '/' . $credential_scope . 
            ',SignedHeaders=' . $signed_headers_str . 
            ',Signature=' . $signature;
        
        return $headers;
    }

    private function make_request($url, $method, $body, $is_streaming = false) {
        $json_body = is_string($body) ? $body : json_encode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to encode request body: ' . json_last_error_msg());
        }

        $headers = $this->sign_request($method, $url, $json_body, $is_streaming);
        
        $header_string = '';
        foreach ($headers as $key => $value) {
            $header_string .= "$key: $value\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $header_string,
                'content' => $json_body,
                'ignore_errors' => true,
                'timeout' => $this->timeout,
                'max_redirects' => 5,
                'protocol_version' => 1.1,
                'follow_location' => 1,
                'user_agent' => 'WordPress/WP-Bedrock',
                'buffer_size' => 8192 * 16  // Increase buffer size for large responses
            ]
        ]);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->log_debug('Body:', $this->sanitize_log_data($body));
        }

        // Try to get response using file_get_contents first
        $response_content = @file_get_contents($url, false, $context);
        if ($response_content === false) {
            // If file_get_contents fails, try fopen as fallback
            $response = @fopen($url, 'r', false, $context);
            if ($response === false) {
                $error = error_get_last();
                $error_message = $error['message'] ?? 'Unknown error';
                $this->log_debug('Request failed: ' . $error_message);
                
                // Check for common AWS errors
                if (strpos($error_message, '403') !== false) {
                    throw new Exception('AWS authentication failed. Please verify your credentials and IAM permissions for Bedrock.');
                } else if (strpos($error_message, '404') !== false) {
                    throw new Exception('AWS Bedrock endpoint not found. Please verify your region and model ID.');
                }
                
                throw new Exception('Failed to open stream: ' . $error_message);
            }
            return $response;
        }

        // For non-streaming, return raw content
        if (!$is_streaming) {
            return $response_content;
        }
        
        // For streaming, create a memory stream
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $response_content);
        rewind($stream);
        return $stream;
    }

    private function execute_with_retry($operation) {
        $last_error = null;
        
        for ($attempt = 1; $attempt <= $this->max_retries; $attempt++) {
            try {
                return $operation();
            } catch (Exception $e) {
                $last_error = $e;
                $this->log_debug("Request failed (attempt $attempt): " . $e->getMessage());
                
                // Special handling for rate limiting
                if ($e->getMessage() === 'HTTP Error: 429') {
                    $delay = $this->retry_delay * pow(2, $attempt - 1);
                    // Add jitter to prevent thundering herd
                    $jitter_amount = $delay * $this->jitter * (wp_rand(0, 100) / 100);
                    $final_delay = $delay + $jitter_amount;
                    
                    $this->log_debug("Rate limited. Retrying in {$final_delay} seconds");
                    sleep($final_delay);
                } else if ($attempt < $this->max_retries) {
                    sleep($this->retry_delay * $attempt);
                }
            }
        }
        
        throw $last_error;
    }

    private function parse_event_data($chunk) {
        $text = $chunk;
        $results = [];

        try {
            // First try to parse as regular JSON
            $parsed = json_decode($text, true);
            if ($parsed === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON');
            }

            // Handle tool use responses first
            if (isset($parsed['tool_calls']) || isset($parsed['function_call'])) {
                $results[] = ['type' => 'tool_call', 'content' => $parsed];
                return $results;
            }

            // Handle base64 encoded bytes
            if (isset($parsed['bytes'])) {
                $decoded = base64_decode($parsed['bytes']);
                try {
                    $decoded_json = json_decode($decoded, true);
                    // Check for tool use in decoded JSON
                    if (isset($decoded_json['tool_calls']) || isset($decoded_json['function_call'])) {
                        $results[] = ['type' => 'tool_call', 'content' => $decoded_json];
                    } else {
                        $results[] = $decoded_json;
                    }
                } catch (Exception $e) {
                    $results[] = ['output' => $decoded];
                }
                return $results;
            }

            // Handle body content
            if (isset($parsed['body']) && is_string($parsed['body'])) {
                try {
                    $parsed_body = json_decode($parsed['body'], true);
                    // Check for tool use in body
                    if (isset($parsed_body['tool_calls']) || isset($parsed_body['function_call'])) {
                        $results[] = ['type' => 'tool_call', 'content' => $parsed_body];
                    } else {
                        $results[] = $parsed_body;
                    }
                } catch (Exception $e) {
                    $results[] = ['output' => $parsed['body']];
                }
                return $results;
            }

            $results[] = isset($parsed['body']) ? $parsed['body'] : $parsed;
            return $results;
        } catch (Exception $e) {
            // If regular JSON parse fails, try to extract event content
            preg_match_all('/:event-type[^\{]+(\{[^\}]+\})/u', $text, $matches);

            if (!empty($matches[1])) {
                foreach ($matches[1] as $event_data) {
                    try {
                        $parsed = json_decode($event_data, true);
                        if (isset($parsed['bytes'])) {
                            $decoded = base64_decode($parsed['bytes']);
                            try {
                                $decoded_json = json_decode($decoded, true);
                                if (isset($decoded_json['tool_calls']) || isset($decoded_json['function_call'])) {
                                    $results[] = ['type' => 'tool_call', 'content' => $decoded_json];
                                } else if (isset($decoded_json['choices'][0]['message']['content'])) {
                                    $results[] = ['output' => $decoded_json['choices'][0]['message']['content']];
                                } else {
                                    $results[] = $decoded_json;
                                }
                            } catch (Exception $e) {
                                $results[] = ['output' => $decoded];
                            }
                        } else {
                            // Check for tool use in event data
                            if (isset($parsed['tool_calls']) || isset($parsed['function_call'])) {
                                $results[] = ['type' => 'tool_call', 'content' => $parsed];
                            } else {
                                $results[] = $parsed;
                            }
                        }
                    } catch (Exception $e) {
                        $this->log_debug('Event parse warning:', $e->getMessage());
                    }
                }
            }

            // If no events were found, try to extract clean text
            if (empty($results)) {
                $clean_text = preg_replace([
                    '/\{KG[^:]+:event-type[^}]+\}/u',
                    '/[\x00-\x1F\x7F-\x9F\uFEFF]/u'
                ], '', $text);
                
                if (!empty(trim($clean_text))) {
                    $results[] = ['output' => trim($clean_text)];
                }
            }
        }

        return $results;
    }

    private function process_stream($response, $callback = null) {
        $buffer = '';
        $tool_id = null;
        
        while (!$response->eof()) {
            $chunk = $response->read(8192);
            if ($chunk === false) break;
            
            $buffer .= $chunk;
            
            // Process complete messages from buffer
            while (($pos = strpos($buffer, "\n")) !== false) {
                $message = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                
                if (!empty($message)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        $this->log_debug('Streaming response:', $this->sanitize_log_data($message));
                    }
                    $events = $this->parse_event_data($message);
                    foreach ($events as $event) {
                        if ($callback) {
                            if (isset($event['type']) && $event['type'] === 'tool_call') {
                                $model_id = get_option('wpbedrock_model_id');
                                $tool_id = uniqid('call_');
                                
                                if (strpos($model_id, 'anthropic.claude') !== false) {
                                    // Format for Claude
                                    $tool_use = [
                                        'role' => 'assistant',
                                        'content' => [
                                            [
                                                'type' => 'tool_use',
                                                'id' => $tool_id,
                                                'name' => $event['content']['tool_calls'][0]['function']['name'] ?? 
                                                        $event['content']['function_call']['name'] ?? '',
                                                'input' => json_decode(
                                                    $event['content']['tool_calls'][0]['function']['arguments'] ?? 
                                                    $event['content']['function_call']['arguments'] ?? '{}',
                                                    true
                                                )
                                            ]
                                        ]
                                    ];
                                    call_user_func($callback, $tool_use);
                                } else if (strpos($model_id, 'mistral.mistral') !== false) {
                                    // Format for Mistral
                                    $tool_use = [
                                        'role' => 'assistant',
                                        'content' => '',
                                        'tool_calls' => [
                                            [
                                                'id' => $tool_id,
                                                'function' => [
                                                    'name' => $event['content']['tool_calls'][0]['function']['name'] ?? 
                                                            $event['content']['function_call']['name'] ?? '',
                                                    'arguments' => $event['content']['tool_calls'][0]['function']['arguments'] ?? 
                                                                 $event['content']['function_call']['arguments'] ?? '{}'
                                                ]
                                            ]
                                        ]
                                    ];
                                    call_user_func($callback, $tool_use);
                                } else if (strpos($model_id, 'amazon.nova') !== false) {
                                    // Format for Nova
                                    $tool_use = [
                                        'role' => 'assistant',
                                        'content' => [
                                            [
                                                'toolUse' => [
                                                    'toolUseId' => $tool_id,
                                                    'name' => $event['content']['tool_calls'][0]['function']['name'] ?? 
                                                            $event['content']['function_call']['name'] ?? '',
                                                    'input' => json_decode(
                                                        $event['content']['tool_calls'][0]['function']['arguments'] ?? 
                                                        $event['content']['function_call']['arguments'] ?? '{}',
                                                        true
                                                    )
                                                ]
                                            ]
                                        ]
                                    ];
                                    call_user_func($callback, $tool_use);
                                }
                            } else if (isset($event['type']) && $event['type'] === 'tool_result') {
                                $model_id = get_option('wpbedrock_model_id');
                                
                                if (strpos($model_id, 'anthropic.claude') !== false && $tool_id) {
                                    // Format for Claude
                                    $tool_result = [
                                        'role' => 'user',
                                        'content' => [
                                            [
                                                'type' => 'tool_result',
                                                'tool_use_id' => $tool_id,
                                                'content' => $event['content']
                                            ]
                                        ]
                                    ];
                                    call_user_func($callback, $tool_result);
                                } else {
                                    call_user_func($callback, $event);
                                }
                            } else if (isset($event['output'])) {
                                // Format text output based on model
                                $model_id = get_option('wpbedrock_model_id');
                                
                                if (strpos($model_id, 'anthropic.claude') !== false) {
                                    // Format for Claude
                                    call_user_func($callback, [
                                        'role' => 'assistant',
                                        'content' => [
                                            [
                                                'type' => 'text',
                                                'text' => $event['output']
                                            ]
                                        ]
                                    ]);
                                } else {
                                    // Default format for other models
                                    call_user_func($callback, [
                                        'role' => 'assistant',
                                        'content' => $event['output']
                                    ]);
                                }
                            } else {
                                // Pass other events as-is
                                call_user_func($callback, $event);
                            }
                        }
                    }
                }
            }
        }
        
        // Process any remaining data in buffer
        if (!empty($buffer)) {
            $events = $this->parse_event_data($buffer);
            foreach ($events as $event) {
                if ($callback) {
                    if (isset($event['type']) && $event['type'] === 'tool_call') {
                        $model_id = get_option('wpbedrock_model_id');
                        $tool_id = uniqid('call_');
                        
                        if (strpos($model_id, 'anthropic.claude') !== false) {
                            // Format for Claude
                            $tool_use = [
                                'role' => 'assistant',
                                'content' => [
                                    [
                                        'type' => 'tool_use',
                                        'id' => $tool_id,
                                        'name' => $event['content']['tool_calls'][0]['function']['name'] ?? 
                                                $event['content']['function_call']['name'] ?? '',
                                        'input' => json_decode(
                                            $event['content']['tool_calls'][0]['function']['arguments'] ?? 
                                            $event['content']['function_call']['arguments'] ?? '{}',
                                            true
                                        )
                                    ]
                                ]
                            ];
                            call_user_func($callback, $tool_use);
                        } else if (strpos($model_id, 'mistral.mistral') !== false) {
                            // Format for Mistral
                            $tool_use = [
                                'role' => 'assistant',
                                'content' => '',
                                'tool_calls' => [
                                    [
                                        'id' => $tool_id,
                                        'function' => [
                                            'name' => $event['content']['tool_calls'][0]['function']['name'] ?? 
                                                    $event['content']['function_call']['name'] ?? '',
                                            'arguments' => $event['content']['tool_calls'][0]['function']['arguments'] ?? 
                                                         $event['content']['function_call']['arguments'] ?? '{}'
                                        ]
                                    ]
                                ]
                            ];
                            call_user_func($callback, $tool_use);
                        } else if (strpos($model_id, 'amazon.nova') !== false) {
                            // Format for Nova
                            $tool_use = [
                                'role' => 'assistant',
                                'content' => [
                                    [
                                        'toolUse' => [
                                            'toolUseId' => $tool_id,
                                            'name' => $event['content']['tool_calls'][0]['function']['name'] ?? 
                                                    $event['content']['function_call']['name'] ?? '',
                                            'input' => json_decode(
                                                $event['content']['tool_calls'][0]['function']['arguments'] ?? 
                                                $event['content']['function_call']['arguments'] ?? '{}',
                                                true
                                            )
                                        ]
                                    ]
                                ]
                            ];
                            call_user_func($callback, $tool_use);
                        }
                    } else if (isset($event['type']) && $event['type'] === 'tool_result') {
                        $model_id = get_option('wpbedrock_model_id');
                        
                        if (strpos($model_id, 'anthropic.claude') !== false && $tool_id) {
                            // Format for Claude
                            $tool_result = [
                                'role' => 'user',
                                'content' => [
                                    [
                                        'type' => 'tool_result',
                                        'tool_use_id' => $tool_id,
                                        'content' => $event['content']
                                    ]
                                ]
                            ];
                            call_user_func($callback, $tool_result);
                        } else {
                            call_user_func($callback, $event);
                        }
                    } else if (isset($event['output'])) {
                        // Format text output based on model
                        $model_id = get_option('wpbedrock_model_id');
                        
                        if (strpos($model_id, 'anthropic.claude') !== false) {
                            // Format for Claude
                            call_user_func($callback, [
                                'role' => 'assistant',
                                'content' => [
                                    [
                                        'type' => 'text',
                                        'text' => $event['output']
                                    ]
                                ]
                            ]);
                        } else {
                            // Default format for other models
                            call_user_func($callback, [
                                'role' => 'assistant',
                                'content' => $event['output']
                            ]);
                        }
                    } else {
                        // Pass other events as-is
                        call_user_func($callback, $event);
                    }
                }
            }
        }
    }

    private $original_settings = [];

    private function setup_stream_environment() {
        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Store original values
        $this->original_settings = array(
            'zlib.output_compression' => ini_get('zlib.output_compression'),
            'implicit_flush' => ini_get('implicit_flush'),
            'output_buffering' => ini_get('output_buffering')
        );

        // We don't use ini_set globally - these settings are only applied to the specific
        // function that needs them for streaming responses

        return $this->original_settings;
    }

    private function restore_environment() {
        foreach ($this->original_settings as $key => $value) {
            if ($value !== false) {
                ini_set($key, $value);
            }
        }
    }

    private function send_sse_message($data) {
        if (connection_status() !== CONNECTION_NORMAL) {
            return;
        }

        echo "data: " . wp_json_encode($data) . "\n\n";
        flush();
    }

    private function extract_content($response, $model_id) {
        if (!$response) return '';

        try {
            $data = is_string($response) ? json_decode($response, true) : $response;
            if (!is_array($data)) return '';

            // Check for tool calls first
            if (isset($data['tool_calls'])) {
                return [
                    'type' => 'tool_use',
                    'name' => $data['tool_calls'][0]['function']['name'],
                    'input' => $data['tool_calls'][0]['function']['arguments']
                ];
            }
            if (isset($data['function_call'])) {
                return [
                    'type' => 'tool_use',
                    'name' => $data['function_call']['name'],
                    'input' => $data['function_call']['arguments']
                ];
            }

            // Claude models
            if (strpos($model_id, 'anthropic.claude') !== false) {
                if (isset($data['content']) && is_array($data['content'])) {
                    foreach ($data['content'] as $content) {
                        if ($content['type'] === 'tool_use') {
                            return [
                                'type' => 'tool_use',
                                'name' => $content['name'],
                                'input' => $content['input']
                            ];
                        }
                    }
                    return implode('', array_map(function($content) {
                        return $content['type'] === 'text' ? ($content['text'] ?? '') : '';
                    }, $data['content']));
                }
            }
            
            // Mistral models
            if (strpos($model_id, 'mistral.mistral') !== false) {
                if (isset($data['choices'][0]['message']['function_call'])) {
                    $function_call = $data['choices'][0]['message']['function_call'];
                    return [
                        'type' => 'tool_use',
                        'name' => $function_call['name'],
                        'input' => $function_call['arguments']
                    ];
                }
                return $data['choices'][0]['message']['content'] ?? '';
            }
            
            // Nova models
            if (strpos($model_id, 'us.amazon.nova') !== false) {
                if (isset($data['results'][0]['toolCalls'])) {
                    $tool_call = $data['results'][0]['toolCalls'][0];
                    return [
                        'type' => 'tool_use',
                        'name' => $tool_call['name'],
                        'input' => $tool_call['arguments']
                    ];
                }
                return $data['results'][0]['outputText'] ?? '';
            }
            
            // Llama models
            if (strpos($model_id, 'meta.llama') !== false) {
                if (isset($data['tool_calls'])) {
                    return [
                        'type' => 'tool_use',
                        'name' => $data['tool_calls'][0]['name'],
                        'input' => $data['tool_calls'][0]['arguments']
                    ];
                }
                return $data['generation'] ?? '';
            }

            return '';
        } catch (Exception $e) {
            $this->log_debug('Content extraction error: ' . $e->getMessage());
            return '';
        }
    }

    public function handle_chat_message($request_data) {
        try {
            // Sanitize request data
            $request_body = json_decode(
                stripslashes(sanitize_text_field($request_data['requestBody'] ?? '{}')), 
                true
            );
            if (empty($request_body)) {
                throw new Exception('Invalid request body');
            }

            $model_id = sanitize_text_field($request_data['model_id'] ?? get_option('wpbedrock_model_id'));
            $stream = isset($request_data['stream']) && sanitize_text_field($request_data['stream']) === '1';
            $tool_id = null;

            if ($stream) {
                $original_settings = $this->setup_stream_environment();
                
                try {
                    $this->invoke_model($request_body, $model_id, true, function($chunk) use ($model_id, &$tool_id) {
                        $this->send_sse_message($chunk);
                    });
                    
                    $this->send_sse_message(['done' => true]);
                } finally {
                    $this->restore_environment();
                }
                exit;
            } else {
                $response = $this->invoke_model($request_body, $model_id);
                $result = json_decode($response, true);
                if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Failed to decode response: ' . json_last_error_msg());
                }
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $this->log_debug('Non-streaming response:', $this->sanitize_log_data($result));
                }
                
                return [
                    'success' => true,
                    'data' => $result
                ];
            }
        } catch (Exception $e) {
            $error = ['error' => true, 'message' => $e->getMessage()];
            if ($stream) {
                $this->send_sse_message($error);
                exit;
            }
            return ['success' => false, 'error' => $error['message']];
        }
    }

    public function invoke_model($request_body, $model_id, $stream = false, $callback = null) {
        try {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->log_debug('Request:', [
                    'model_id' => $model_id,
                    'stream' => $stream,
                    'body' => $this->sanitize_log_data($request_body)
                ]);
            }

            $url = $this->get_bedrock_endpoint($model_id, $stream);

            if ($stream) {
                $response = $this->execute_with_retry(function() use ($url, $request_body) {
                    return $this->make_request($url, 'POST', $request_body, true);
                });
                $this->process_stream($response, $callback);
                return null;
            } else {
                return $this->execute_with_retry(function() use ($url, $request_body) {
                    return $this->make_request($url, 'POST', $request_body, false);
                });
            }
        } catch (Exception $e) {
            throw new Exception('Error invoking Bedrock model: ' . $e->getMessage());
        }
    }
}
