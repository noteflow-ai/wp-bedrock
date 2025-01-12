<?php
/**
 * AWS Bedrock client class
 *
 * @since      1.0.0
 * @package    WP_Bedrock
 * @subpackage WP_Bedrock/includes
 */

namespace WPBEDROCK;

use Exception;

class WP_Bedrock_AWS {
    private $access_key;
    private $secret_key;
    private $region;
    private $max_retries = 3;
    private $retry_delay = 1; // seconds

    /**
     * Log debug message if WP_DEBUG_LOG is enabled
     */
    private function log_debug($message, $data = null) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_message = '[AI Chat for Amazon Bedrock] ' . $message;
            if ($data !== null) {
                $log_message .= ' ' . (is_string($data) ? $data : json_encode($data));
            }
            error_log($log_message);
        }
    }

    /**
     * Initialize AWS Bedrock client
     */
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
    }

    /**
     * Execute with retry logic
     */
    private function execute_with_retry($operation) {
        $last_exception = null;
        
        for ($attempt = 1; $attempt <= $this->max_retries; $attempt++) {
            try {
                return $operation();
            } catch (Exception $e) {
                $last_exception = $e;
                $this->log_debug("Request failed (attempt $attempt of {$this->max_retries}): " . $e->getMessage());
                
                if ($attempt < $this->max_retries) {
                    sleep($this->retry_delay * $attempt);
                }
            }
        }
        
        throw new Exception("Connection failed after {$this->max_retries} retries: " . $last_exception->getMessage());
    }

    /**
     * Get canonical URI from path
     */
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

    /**
     * Create HMAC signature
     */
    private function create_hmac($key, $string) {
        return hash_hmac('sha256', $string, $key, true);
    }

    /**
     * Get AWS v4 signing key
     */
    private function get_signing_key($secret_key, $date_stamp, $region, $service) {
        $k_date = $this->create_hmac('AWS4' . $secret_key, $date_stamp);
        $k_region = $this->create_hmac($k_date, $region);
        $k_service = $this->create_hmac($k_region, $service);
        return $this->create_hmac($k_service, 'aws4_request');
    }

    /**
     * Sign AWS request
     */
    private function sign_request($method, $url, $body, $is_streaming = true) {
        $parsed_url = parse_url($url);
        $canonical_uri = $this->get_canonical_uri($parsed_url['path']);
        $canonical_query = isset($parsed_url['query']) ? $parsed_url['query'] : '';

        $now = gmdate('Ymd\THis\Z');
        $date_stamp = substr($now, 0, 8);

        // Calculate payload hash
        $payload_hash = hash('sha256', is_string($body) ? $body : json_encode($body));

        // Prepare headers
        $headers = [
            'accept' => $is_streaming ? 'application/vnd.amazon.eventstream' : 'application/json',
            'content-type' => 'application/json',
            'host' => $parsed_url['host'],
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date' => $now
        ];

        if ($is_streaming) {
            $headers['x-amzn-bedrock-accept'] = '*/*';
        }

        // Create canonical request
        ksort($headers);
        $canonical_headers = '';
        $signed_headers = '';
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            $signed_headers .= strtolower($key) . ';';
        }
        $signed_headers = rtrim($signed_headers, ';');

        $canonical_request = implode("\n", [
            strtoupper($method),
            $canonical_uri,
            $canonical_query,
            $canonical_headers,
            $signed_headers,
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

        // Calculate signature
        $signing_key = $this->get_signing_key($this->secret_key, $date_stamp, $this->region, 'bedrock');
        $signature = bin2hex($this->create_hmac($signing_key, $string_to_sign));

        // Create authorization header
        $authorization = $algorithm . ' ' .
            'Credential=' . $this->access_key . '/' . $credential_scope . ', ' .
            'SignedHeaders=' . $signed_headers . ', ' .
            'Signature=' . $signature;

        $headers['Authorization'] = $authorization;
        return $headers;
    }

    /**
     * Parse event data from chunk
     */
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

    /**
     * Process streaming response
     */
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

    /**
     * Make HTTP request to Bedrock endpoint
     */
    private function make_request($url, $method, $body, $is_streaming = false) {
        $headers = $this->sign_request($method, $url, $body, $is_streaming);
        
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", array_map(
                    function($k, $v) { return "$k: $v"; },
                    array_keys($headers),
                    $headers
                )),
                'content' => is_string($body) ? $body : json_encode($body),
                'ignore_errors' => true
            ]
        ]);

        $response = fopen($url, 'r', false, $context);
        if ($response === false) {
            throw new Exception('Failed to open stream: HTTP request failed');
        }

        return $response;
    }

    /**
     * Get Bedrock endpoint URL
     */
    private function get_bedrock_endpoint($model_id, $stream = false) {
        $base_endpoint = "https://bedrock-runtime.{$this->region}.amazonaws.com";
        return $stream
            ? "{$base_endpoint}/model/{$model_id}/invoke-with-response-stream"
            : "{$base_endpoint}/model/{$model_id}/invoke";
    }

    /**
     * Invoke Bedrock model
     */
    public function invoke_model($request_body, $model_id, $stream = false, $callback = null) {
        try {
            // Ensure request body is properly formatted for Claude models
            if (strpos($model_id, 'anthropic.claude') !== false) {
                $request_body['anthropic_version'] = 'bedrock-2023-05-31';
            }

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

                // Log non-streaming response for debugging
                $this->log_debug('Non-streaming response:', json_encode($result, JSON_PRETTY_PRINT));

                // Check for tool calls in response
                if (isset($result['tool_calls'])) {
                    $this->log_debug('Tool calls found in non-streaming response:', json_encode($result['tool_calls'], JSON_PRETTY_PRINT));
                }

                // Extract output text from Nova response
                if (strpos($model_id, 'us.amazon.nova') !== false) {
                    if (!isset($result['output_text'])) {
                        throw new Exception('Invalid Nova model response: missing output_text');
                    }
                    return ['content' => [['text' => $result['output_text']]]];
                }

                return $result;
            }
        } catch (Exception $e) {
            throw new Exception('Error invoking Bedrock model: ' . $e->getMessage());
        }
    }

    /**
     * Generate images using Bedrock image models
     */
    public function generate_image($request_body, $model_id) {
        try {
            $url = $this->get_bedrock_endpoint($model_id, false);

            $this->log_debug('Image generation request:', [
                'model_id' => $model_id,
                'body' => $request_body
            ]);

            $response = $this->execute_with_retry(function() use ($url, $request_body) {
                return $this->make_request($url, 'POST', $request_body, false);
            });

            $response_content = stream_get_contents($response);
            $result = json_decode($response_content, true);

            if (!isset($result['artifacts']) || empty($result['artifacts'])) {
                throw new Exception('No images generated in response');
            }

            return $result['artifacts'];
        } catch (Exception $e) {
            throw new Exception('Error generating image: ' . $e->getMessage());
        }
    }

    /**
     * Upscale an image using Stable Diffusion
     */
    public function upscale_image($request_body) {
        try {
            $url = $this->get_bedrock_endpoint('stability.stable-diffusion-xl-upscaler', false);

            $response = $this->execute_with_retry(function() use ($url, $request_body) {
                return $this->make_request($url, 'POST', $request_body, false);
            });

            $response_content = stream_get_contents($response);
            $result = json_decode($response_content, true);

            if (!isset($result['artifacts']) || empty($result['artifacts'])) {
                throw new Exception('No upscaled image in response');
            }

            return $result['artifacts'][0];
        } catch (Exception $e) {
            throw new Exception('Error upscaling image: ' . $e->getMessage());
        }
    }

    /**
     * Create a variation of an existing image
     */
    public function create_image_variation($request_body) {
        try {
            $url = $this->get_bedrock_endpoint('stability.stable-diffusion-xl-v1', false);

            $response = $this->execute_with_retry(function() use ($url, $request_body) {
                return $this->make_request($url, 'POST', $request_body, false);
            });

            $response_content = stream_get_contents($response);
            $result = json_decode($response_content, true);

            if (!isset($result['artifacts']) || empty($result['artifacts'])) {
                throw new Exception('No variation image in response');
            }

            return $result['artifacts'][0];
        } catch (Exception $e) {
            throw new Exception('Error creating image variation: ' . $e->getMessage());
        }
    }
}
