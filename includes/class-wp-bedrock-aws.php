<?php
namespace WPBEDROCK;

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

    private function log_debug($message, $data = null) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_message = '[AI Chat for Amazon Bedrock] ' . $message;
            if ($data !== null) {
                $log_message .= ' ' . (is_string($data) ? $data : json_encode($data));
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
        $endpoint = "https://bedrock-runtime.{$this->region}.amazonaws.com";
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
        $parsed_url = parse_url($url);
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
                'timeout' => $this->timeout
            ]
        ]);

        $this->log_debug('Body:', $body);

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

        $meta = stream_get_meta_data($response);
        foreach ($meta['wrapper_data'] as $header) {
            if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                $status = intval($matches[1]);
                if ($status >= 400) {
                    throw new Exception("HTTP Error: $status");
                }
                break;
            }
        }

        return $response;
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
                    $jitter_amount = $delay * $this->jitter * (rand(0, 100) / 100);
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

            if (isset($parsed['bytes'])) {
                $decoded = base64_decode($parsed['bytes']);
                try {
                    $decoded_json = json_decode($decoded, true);
                    $results[] = $decoded_json;
                } catch (Exception $e) {
                    $results[] = ['output' => $decoded];
                }
                return $results;
            }

            if (isset($parsed['body']) && is_string($parsed['body'])) {
                try {
                    $parsed_body = json_decode($parsed['body'], true);
                    $results[] = $parsed_body;
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
                                if (isset($decoded_json['choices'][0]['message']['content'])) {
                                    $results[] = ['output' => $decoded_json['choices'][0]['message']['content']];
                                } else {
                                    $results[] = $decoded_json;
                                }
                            } catch (Exception $e) {
                                $results[] = ['output' => $decoded];
                            }
                        } else {
                            $results[] = $parsed;
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
        
        while (!$response->eof()) {
            $chunk = $response->read(8192);
            if ($chunk === false) break;
            
            $buffer .= $chunk;
            
            // Process complete messages from buffer
            while (($pos = strpos($buffer, "\n")) !== false) {
                $message = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                
                if (!empty($message)) {
                    $this->log_debug('Streaming response:', json_encode($message, JSON_PRETTY_PRINT));
                    $events = $this->parse_event_data($message);
                    foreach ($events as $event) {
                        if ($callback) {
                            call_user_func($callback, $event);
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
                    call_user_func($callback, $event);
                }
            }
        }
    }

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

        @ini_set('zlib.output_compression', 'Off');
        @ini_set('implicit_flush', true);
        @ini_set('output_buffering', 'Off');
    }

    private function send_sse_message($data) {
        if (connection_status() !== CONNECTION_NORMAL) {
            return;
        }

        echo "data: " . json_encode($data) . "\n\n";
        flush();
    }

    private function extract_content($response, $model_id) {
        if (!$response) return '';

        try {
            $data = is_string($response) ? json_decode($response, true) : $response;
            if (!is_array($data)) return '';

            // Claude models
            if (strpos($model_id, 'anthropic.claude') !== false) {
                if (isset($data['content']) && is_array($data['content'])) {
                    return implode('', array_map(function($content) {
                        return $content['type'] === 'text' ? ($content['text'] ?? '') : '';
                    }, $data['content']));
                }
            }
            
            // Mistral models
            if (strpos($model_id, 'mistral.mistral') !== false) {
                return $data['choices'][0]['message']['content'] ?? '';
            }
            
            // Nova models
            if (strpos($model_id, 'us.amazon.nova') !== false) {
                return $data['results'][0]['outputText'] ?? '';
            }
            
            // Llama models
            if (strpos($model_id, 'meta.llama') !== false) {
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
            $request_body = json_decode(stripslashes($request_data['requestBody'] ?? '{}'), true);
            if (empty($request_body)) {
                throw new Exception('Invalid request body');
            }

            $model_id = $request_data['model_id'] ?? get_option('wpbedrock_model_id');
            $stream = isset($request_data['stream']) && $request_data['stream'] === '1';

            if ($stream) {
                $this->setup_stream_environment();
                
                $this->invoke_model($request_body, $model_id, true, function($chunk) use ($model_id) {
                    $content = $this->extract_content($chunk, $model_id);
                    if ($content) {
                        $this->send_sse_message(['text' => $content]);
                    }
                });
                
                $this->send_sse_message(['done' => true]);
                exit;
            } else {
                $response = $this->invoke_model($request_body, $model_id);
                return [
                    'success' => true,
                    'data' => $response
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
            $this->log_debug('Request:', [
                'model_id' => $model_id,
                'stream' => $stream,
                'body' => $request_body
            ]);

            $url = $this->get_bedrock_endpoint($model_id, $stream);

            if ($stream) {
                $response = $this->execute_with_retry(function() use ($url, $request_body) {
                    return $this->make_request($url, 'POST', $request_body, true);
                });
                $this->process_stream($response, $callback);
                return null;
            } else {
                $response = $this->execute_with_retry(function() use ($url, $request_body) {
                    return $this->make_request($url, 'POST', $request_body, false);
                });

                $response_content = stream_get_contents($response);
                $result = json_decode($response_content, true);
                
                if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Failed to decode response: ' . json_last_error_msg());
                }

                $this->log_debug('Non-streaming response:', json_encode($result, JSON_PRETTY_PRINT));
                return $result;
            }
        } catch (Exception $e) {
            throw new Exception('Error invoking Bedrock model: ' . $e->getMessage());
        }
    }
}
